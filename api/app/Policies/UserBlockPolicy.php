<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserBlock;

class UserBlockPolicy
{
    /**
     * Only the blocker can undo their own block - the blocked side has no
     * say in it, same as they never see the block exists in the first
     * place (see BlockService::isBlocked()'s docblock).
     */
    public function delete(User $user, UserBlock $block): bool
    {
        return $user->id === $block->blocker_id;
    }
}
