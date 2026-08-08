<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserGame;

class UserGamePolicy
{
    public function update(User $user, UserGame $userGame): bool
    {
        return $user->id === $userGame->user_id;
    }

    public function delete(User $user, UserGame $userGame): bool
    {
        return $user->id === $userGame->user_id;
    }
}
