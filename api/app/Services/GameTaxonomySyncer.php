<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use Illuminate\Support\Collection;

class GameTaxonomySyncer
{
    /**
     * Exact replace, used by the manual add/edit form: the user sees the
     * full current list as tags and edits it directly (including removing
     * one), so what they submit should become the full list, dropping
     * whatever isn't there anymore.
     *
     * @param  array<int, string>  $mechanicNames
     * @param  array<int, string>  $categoryNames
     */
    public function sync(Game $game, array $mechanicNames, array $categoryNames): void
    {
        $mechanicIds = $this->resolveIds(Mechanic::class, $mechanicNames);
        $categoryIds = $this->resolveIds(Category::class, $categoryNames);

        $game->mechanics()->sync($mechanicIds);
        $game->categories()->sync($categoryIds);
    }

    /**
     * Additive only, used when re-importing from BGG: unlike the manual
     * form, BGG's own list for a game is the only thing being applied here,
     * so an exact sync() would silently discard any mechanic/category the
     * user tagged by hand that BGG doesn't happen to report (BGG's own
     * classification is comprehensive but not exhaustive, and a user might
     * reasonably add something specific). This never drops a tag on
     * re-import - the tradeoff is that a tag BGG later stops reporting for
     * a game (a reclassification, not something we've actually observed)
     * stays attached until removed by hand.
     *
     * @param  array<int, string>  $mechanicNames
     * @param  array<int, string>  $categoryNames
     */
    public function syncFromBgg(Game $game, array $mechanicNames, array $categoryNames): void
    {
        $mechanicIds = $this->resolveIds(Mechanic::class, $mechanicNames);
        $categoryIds = $this->resolveIds(Category::class, $categoryNames);

        $game->mechanics()->syncWithoutDetaching($mechanicIds);
        $game->categories()->syncWithoutDetaching($categoryIds);
    }

    /**
     * @param  class-string<Mechanic>|class-string<Category>  $modelClass
     * @param  array<int, string>  $names
     * @return Collection<int, int>
     */
    private function resolveIds(string $modelClass, array $names): Collection
    {
        return collect($names)->map(fn (string $name) => $modelClass::firstOrCreate(['name' => $name])->id);
    }
}
