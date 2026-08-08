<?php

namespace App\Policies;

use App\Models\BggImport;
use App\Models\User;

class BggImportPolicy
{
    public function view(User $user, BggImport $bggImport): bool
    {
        return $user->id === $bggImport->user_id;
    }
}
