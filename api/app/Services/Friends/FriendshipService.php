<?php

namespace App\Services\Friends;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FriendshipService
{
    /**
     * A single match, `discoverable` only, never `$me` themselves. Returns
     * null both when nothing matches AND when a real account exists but
     * isn't discoverable - the caller (FriendshipController::search()) must
     * never be able to tell those two cases apart, or this becomes an
     * oracle for which emails are registered (same class of issue already
     * hardened against on `/forgot-password`).
     */
    public function search(User $me, ?string $email, ?string $bggUsername): ?User
    {
        $query = User::query()
            ->where('id', '!=', $me->id)
            ->where('discoverable', true);

        if ($email !== null) {
            $query->where('email', $email);
        } else {
            $query->where('bgg_username', $bggUsername);
        }

        return $query->first();
    }

    /**
     * `$targetId` not existing at all and existing-but-not-discoverable are
     * deliberately the same error (`not_discoverable`) - user ids are
     * sequential integers, trivial to guess/scan, so a 404 on a missing one
     * vs. a validation error on a real-but-private one would itself be an
     * oracle for which ids are in use, same reasoning as `search()` above
     * never distinguishing "doesn't exist" from "exists but private".
     *
     * `$target->discoverable` gates being a valid request target at all,
     * not just search visibility - otherwise someone who already knows a
     * user_id (guessed, seen in a URL) could route around "I don't want to
     * be found" entirely. If `$target` already sent `$me` a pending
     * request, this accepts it instead of creating a duplicate row -
     * two people adding each other around the same time is a real case,
     * not an edge case to error out on.
     */
    public function sendRequest(User $me, int $targetId): Friendship
    {
        if ($targetId === $me->id) {
            throw ValidationException::withMessages([
                'user_id' => [__('friends.cannot_add_yourself')],
            ]);
        }

        $target = User::where('id', $targetId)->where('discoverable', true)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'user_id' => [__('friends.not_discoverable')],
            ]);
        }

        $reverse = Friendship::where('requester_id', $target->id)
            ->where('recipient_id', $me->id)
            ->first();

        if ($reverse !== null) {
            if ($reverse->status === 'pending') {
                $reverse->update(['status' => 'accepted']);

                return $reverse;
            }

            throw ValidationException::withMessages([
                'user_id' => [__('friends.already_friends')],
            ]);
        }

        $existing = Friendship::where('requester_id', $me->id)
            ->where('recipient_id', $target->id)
            ->first();

        if ($existing !== null) {
            $message = $existing->status === 'accepted' ? 'friends.already_friends' : 'friends.already_requested';

            throw ValidationException::withMessages(['user_id' => [__($message)]]);
        }

        return Friendship::create([
            'requester_id' => $me->id,
            'recipient_id' => $target->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Returns the Friendship rows themselves (not just the other User) -
     * the controller needs each row's own id too, since "quitar amigo" is
     * DELETE /friends/requests/{friendship}, the same endpoint that also
     * handles declining/cancelling a pending request.
     *
     * @return Collection<int, Friendship>
     */
    public function friendsFor(User $user): Collection
    {
        return Friendship::accepted()
            ->where(function ($query) use ($user) {
                $query->where('requester_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
            })
            ->with(['requester', 'recipient'])
            ->get();
    }
}
