<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;

class GameTaxonomySyncer
{
    /**
     * @param  array<int, string>  $mechanicNames
     * @param  array<int, string>  $categoryNames
     */
    public function sync(Game $game, array $mechanicNames, array $categoryNames): void
    {
        $mechanicIds = collect($mechanicNames)
            ->map(fn (string $name) => Mechanic::firstOrCreate(['name' => $name])->id);

        $categoryIds = collect($categoryNames)
            ->map(fn (string $name) => Category::firstOrCreate(['name' => $name])->id);

        $game->mechanics()->sync($mechanicIds);
        $game->categories()->sync($categoryIds);
    }
}
