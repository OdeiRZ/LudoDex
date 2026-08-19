<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Play;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class PlayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'bgg_play_id' => fake()->unique()->numberBetween(1, 100_000_000),
            'played_at' => fake()->dateTimeBetween('-2 years')->format('Y-m-d'),
            'quantity' => 1,
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90, null]),
        ];
    }
}
