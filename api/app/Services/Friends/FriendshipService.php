<?php

namespace App\Services\Friends;

use App\Models\Friendship;
use App\Models\User;
use App\Notifications\FriendRequestReceivedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FriendshipService
{
    public function __construct(private readonly BlockService $blockService) {}

    /**
     * A single match, `discoverable` only, never `$me` themselves. Returns
     * null both when nothing matches AND when a real account exists but
     * isn't discoverable - the caller (FriendshipController::search()) must
     * never be able to tell those two cases apart, or this becomes an
     * oracle for which emails are registered (same class of issue already
     * hardened against on `/forgot-password`). A blocked match (in either
     * direction) is folded into that same null outcome for the same
     * reason - if a block produced a different result than "not
     * discoverable", that difference would itself tell the blocked user
     * they've been blocked specifically, rather than just not found.
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

        $result = $query->first();

        if ($result !== null && $this->blockService->isBlocked($me, $result)) {
            return null;
        }

        return $result;
    }

    /**
     * `$targetId` not existing at all, existing-but-not-discoverable, and
     * existing-but-blocked (in either direction) are all deliberately the
     * same error (`not_discoverable`) - user ids are
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

        if ($target === null || $this->blockService->isBlocked($me, $target)) {
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

        $friendship = Friendship::create([
            'requester_id' => $me->id,
            'recipient_id' => $target->id,
            'status' => 'pending',
        ]);

        // Only reached for a genuinely new pending row - the auto-accept
        // branch above (a mutual request) returns before this, since
        // there's nothing "pending" left to notify about there.
        //
        // Caught broadly and only logged, not left to propagate: the
        // request itself is already saved at this point (found the hard
        // way testing locally - a mail transport failure otherwise turned
        // an already-successful request into a 500, with no way for the
        // user to tell their request had actually gone through).
        try {
            $target->notify(new FriendRequestReceivedNotification($me));
        } catch (\Throwable $e) {
            Log::warning('Friend request notification email failed to send', [
                'recipient_id' => $target->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $friendship;
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

    /**
     * Symmetric (doesn't matter who requested whom) and only ever true for
     * `accepted` - a pending or declined row never counts. Used to gate the
     * Fase 2 read-only endpoints (collection comparison, a friend's plays):
     * those must 404 identically whether `$b`'s id doesn't exist at all or
     * exists but isn't an accepted friend of `$a` - see each caller's own
     * `abort_unless(..., 404)` for why that's never a 403.
     *
     * No need to short-circuit `$a->id === $b->id` - a Friendship row is
     * never self-referencing, so this already returns false for that case.
     */
    public function areFriends(User $a, User $b): bool
    {
        return Friendship::accepted()
            ->where(function ($query) use ($a, $b) {
                $query->where('requester_id', $a->id)->where('recipient_id', $b->id);
            })
            ->orWhere(function ($query) use ($a, $b) {
                $query->where('requester_id', $b->id)->where('recipient_id', $a->id);
            })
            ->exists();
    }

    /**
     * Deliberately does NOT rely on Laravel's implicit route-model-binding
     * for `{friend}` (which would throw a ModelNotFoundException carrying
     * "No query results for model [User] 123" as its message) - that
     * message differs from a plain `abort(404)`, which would make "the id
     * doesn't exist" distinguishable from "exists but isn't an accepted
     * friend" even in production (Laravel keeps HttpException messages in
     * the JSON response regardless of APP_DEBUG). Looking the user up here
     * and funnelling both cases through the exact same `abort(404)` call
     * guarantees a byte-identical response either way - confirmed by a
     * literal body comparison in FriendCollectionTest/FriendPlaysIndexTest/
     * FriendPlaysStatsTest.
     */
    public function resolveAcceptedFriend(User $me, int $friendId): User
    {
        $friend = User::find($friendId);

        abort_unless($friend !== null && $this->areFriends($me, $friend), 404);

        return $friend;
    }
}
