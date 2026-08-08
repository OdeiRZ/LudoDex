<?php

namespace Database\Factories;

use App\Models\BggImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BggImport>
 */
class BggImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bgg_username' => fake()->userName(),
            'status' => 'pending',
        ];
    }
}
