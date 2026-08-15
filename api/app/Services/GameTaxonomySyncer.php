<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use Illuminate\Support\Carbon;
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
     * One SELECT for names that already exist, one batched INSERT (with
     * duplicates left to ON CONFLICT DO NOTHING) for whatever's new, and a
     * second SELECT to pick up the ids that insert just created - instead
     * of a firstOrCreate() per name, which was up to two individual
     * queries per mechanic/category on every single game in an import.
     *
     * @param  class-string<Mechanic>|class-string<Category>  $modelClass
     * @param  array<int, string>  $names
     * @return Collection<int, int>
     */
    private function resolveIds(string $modelClass, array $names): Collection
    {
        if ($names === []) {
            return collect();
        }

        $names = collect($names)->unique()->values();

        $idsByName = $modelClass::query()->whereIn('name', $names)->pluck('id', 'name');

        $missing = $names->diff($idsByName->keys());

        if ($missing->isNotEmpty()) {
            $now = Carbon::now();

            $modelClass::query()->insertOrIgnore(
                $missing->map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all()
            );

            $idsByName = $modelClass::query()->whereIn('name', $names)->pluck('id', 'name');
        }

        return $names->map(fn (string $name) => $idsByName[$name]);
    }
}
