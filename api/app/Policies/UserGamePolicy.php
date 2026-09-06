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

    /**
     * `update()` above only checks ownership of the join row (`user_games`),
     * which is enough to change this user's own `status` - but
     * `UserGameController::update()` also uses it to gate edits to the
     * underlying `Game` row, and that row is a catalog shared across every
     * user (see `UserGameController::clear()`'s own comment). Without this,
     * anyone with a single game in their collection could rewrite its name,
     * rating, player counts, etc. for every other user who also has it -
     * found in a security audit. Only allow it while no one else has this
     * game yet, i.e. exactly the "fix a typo in what I just added" case the
     * edit form exists for; once a second user adds the same game, further
     * catalog-level edits need going through BGG re-import instead of a
     * unilateral edit by whoever happens to have it too.
     */
    public function updateGame(User $user, UserGame $userGame): bool
    {
        return ! UserGame::where('game_id', $userGame->game_id)
            ->where('user_id', '!=', $user->id)
            ->exists();
    }

    public function delete(User $user, UserGame $userGame): bool
    {
        return $user->id === $userGame->user_id;
    }
}
