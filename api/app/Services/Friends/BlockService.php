<?php

namespace App\Services\Friends;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BlockService
{
    /**
     * `firstOrCreate` makes this idempotent - blocking someone already
     * blocked isn't an error, it just avoids inventing an "already
     * blocked" message that would be an unnecessary distinction (the
     * caller already knows they just clicked "block" on someone in their
     * blocked list, or a stranger - either way the end state is the same).
     */
    public function block(User $blocker, int $targetId): UserBlock
    {
        if ($targetId === $blocker->id) {
            throw ValidationException::withMessages([
                'user_id' => [__('friends.cannot_block_yourself')],
            ]);
        }

        $block = UserBlock::firstOrCreate([
            'blocker_id' => $blocker->id,
            'blocked_id' => $targetId,
        ]);

        // A block ends any existing relationship between the two, in
        // either direction and either status (pending or accepted) - a
        // block leaving a stale friendships row behind would defeat the
        // point (the blocked user would still show up as a friend, or a
        // pending request would still be sitting there to accept).
        Friendship::where(function ($query) use ($blocker, $targetId) {
            $query->where('requester_id', $blocker->id)->where('recipient_id', $targetId);
        })->orWhere(function ($query) use ($blocker, $targetId) {
            $query->where('requester_id', $targetId)->where('recipient_id', $blocker->id);
        })->delete();

        return $block;
    }

    public function unblock(User $blocker, int $targetId): void
    {
        UserBlock::where('blocker_id', $blocker->id)->where('blocked_id', $targetId)->delete();
    }

    /**
     * @return Collection<int, UserBlock>
     */
    public function blockedFor(User $user): Collection
    {
        return UserBlock::where('blocker_id', $user->id)->with('blocked')->get();
    }

    /**
     * Symmetric on purpose: if EITHER side has blocked the other, neither
     * can search for or send a request to the other - see
     * FriendshipService::search()/sendRequest(), which fold this into the
     * same opaque `not_discoverable` outcome rather than a distinguishable
     * one, so being blocked is never observable from the blocked side.
     */
    public function isBlocked(User $a, User $b): bool
    {
        return UserBlock::where(function ($query) use ($a, $b) {
            $query->where('blocker_id', $a->id)->where('blocked_id', $b->id);
        })->orWhere(function ($query) use ($a, $b) {
            $query->where('blocker_id', $b->id)->where('blocked_id', $a->id);
        })->exists();
    }
}
