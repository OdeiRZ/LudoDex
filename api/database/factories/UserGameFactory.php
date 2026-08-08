<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use App\Models\UserGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserGame>
 */
class UserGameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'status' => 'owned',
        ];
    }
}
