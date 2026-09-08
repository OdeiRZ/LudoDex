<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;

class FriendshipPolicy
{
    public function accept(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->recipient_id && $friendship->status === 'pending';
    }

    /**
     * Covers rejecting a pending request (by the recipient), cancelling one
     * (by the requester) and unfriending (either side, once accepted) - all
     * the same underlying action (FriendshipController::destroy() deletes
     * the row), so one rule for all three: either party to this specific
     * row, in any status.
     */
    public function delete(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->requester_id || $user->id === $friendship->recipient_id;
    }
}
