<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        $minPlayers = fake()->numberBetween(1, 4);

        return [
            'name' => fake()->words(3, true),
            'min_players' => $minPlayers,
            'max_players' => $minPlayers + fake()->numberBetween(0, 4),
            'min_playtime_minutes' => fake()->randomElement([15, 30, 45, 60]),
            'max_playtime_minutes' => fake()->randomElement([60, 90, 120, 180]),
            'weight' => fake()->randomFloat(2, 1, 5),
            'is_cooperative' => false,
            'is_competitive' => true,
            'has_campaign' => false,
        ];
    }

    public function cooperative(): static
    {
        return $this->state(fn () => ['is_cooperative' => true, 'is_competitive' => false]);
    }
}
