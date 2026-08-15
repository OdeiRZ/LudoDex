<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Batched equivalent of syncFromBgg() for every game in a BGG import at
     * once, instead of the same handful of round-trips repeated per game -
     * a ~500-game import previously meant ~500 separate resolveIds() calls
     * plus ~500 pairs of pivot syncWithoutDetaching() calls, each of which
     * is its own query. Resolving mechanics/categories still costs one
     * SELECT (+ one insertOrIgnore for whatever's new) each, but across the
     * whole import's combined name list rather than per game, and the
     * pivot rows for every game go into one insertOrIgnore per pivot table.
     * insertOrIgnore already skips a (game_id, tag_id) pair that's
     * attached, so - same as syncFromBgg() - this never needs to check
     * what's already there first to stay additive-only.
     *
     * @param  array<string, array{mechanics: list<string>, categories: list<string>}>  $tagsByGameId
     */
    public function syncManyFromBgg(array $tagsByGameId): void
    {
        $this->syncManyPivot(
            Mechanic::class,
            'game_mechanic',
            'mechanic_id',
            array_map(fn (array $tags) => $tags['mechanics'], $tagsByGameId),
        );

        $this->syncManyPivot(
            Category::class,
            'category_game',
            'category_id',
            array_map(fn (array $tags) => $tags['categories'], $tagsByGameId),
        );
    }

    /**
     * @param  class-string<Mechanic>|class-string<Category>  $modelClass
     * @param  array<string, list<string>>  $namesByGameId
     */
    private function syncManyPivot(string $modelClass, string $pivotTable, string $foreignKey, array $namesByGameId): void
    {
        $allNames = collect($namesByGameId)->flatten()->all();
        $idsByName = $this->resolveIdsByName($modelClass, $allNames);

        $pivotRows = [];

        foreach ($namesByGameId as $gameId => $names) {
            foreach (array_unique($names) as $name) {
                $pivotRows[] = ['game_id' => $gameId, $foreignKey => $idsByName[$name]];
            }
        }

        if ($pivotRows !== []) {
            DB::table($pivotTable)->insertOrIgnore($pivotRows);
        }
    }

    /**
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
        $idsByName = $this->resolveIdsByName($modelClass, $names->all());

        return $names->map(fn (string $name) => $idsByName[$name]);
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
     * @return Collection<string, int> name => id
     */
    private function resolveIdsByName(string $modelClass, array $names): Collection
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

        return $idsByName;
    }
}
