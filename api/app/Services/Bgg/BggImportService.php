<?php

namespace App\Services\Bgg;

use App\Models\BggImport;
use App\Models\Game;
use App\Models\UserGame;
use App\Services\GameTaxonomySyncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BggImportService
{
    /**
     * A single BGG hiccup (a dropped connection, a 5xx, a garbled body)
     * shouldn't strand an import in a permanent 'failed' state that the
     * polling loop can never recover from (see attempt()'s own re-entrant
     * guard above) - only give up once several polls in a row have all hit
     * a transient error, since each poll is itself several minutes apart.
     */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(
        private readonly BggClient $client,
        private readonly GameTaxonomySyncer $taxonomySyncer,
    ) {}

    /**
     * Re-entrant: safe to call repeatedly (that's the polling model). Does
     * nothing once the import has already left the pending state.
     */
    public function attempt(BggImport $import): void
    {
        if ($import->status !== 'pending') {
            return;
        }

        $collection = $this->client->fetchCollection($import->bgg_username);

        if ($collection['status'] === 'pending') {
            return;
        }

        if ($collection['status'] === 'error') {
            $isTransient = $collection['transient'] ?? false;

            if ($isTransient && $import->failed_attempts + 1 < self::MAX_CONSECUTIVE_FAILURES) {
                $import->increment('failed_attempts');

                return;
            }

            $import->update(['status' => 'failed', 'error_message' => $collection['message']]);

            return;
        }

        if ($import->failed_attempts > 0) {
            $import->update(['failed_attempts' => 0]);
        }

        // A real BGG collection can list the same objectid more than once
        // (observed live: a ~500-game collection with one duplicate) - the
        // old per-item updateOrCreate() loop tolerated that silently (just
        // wrote the same row twice), but a single batched upsert() can't:
        // Postgres rejects "ON CONFLICT DO UPDATE" affecting the same row
        // twice within one command. Collapsed here, before anything else
        // reads $items, so every later step only ever sees one row per
        // bgg_id.
        $items = $this->dedupeByBggId($collection['items']);
        $details = $this->client->fetchGameDetails(array_column($items, 'bgg_id'));

        DB::transaction(function () use ($import, $items, $details) {
            $gamesByBggId = $this->upsertGames($items, $details);
            $this->linkExpansions($items, $details, $gamesByBggId);
            $this->syncUserCollection($import, $items, $gamesByBggId);

            $import->update(['status' => 'completed', 'imported_count' => count($items)]);
        });
    }

    /**
     * One bulk upsert for every game in the import instead of an
     * updateOrCreate() per game - a ~500-game import previously meant ~500
     * individual SELECT+INSERT/UPDATE round-trips just for this part.
     * Eloquent's own id/timestamp handling is bypassed by a raw upsert, so
     * both are filled in by hand here; an existing game's id survives
     * because it's excluded from the ON CONFLICT update column list, and
     * the freshly-generated id on a row that turns out to conflict is
     * simply never used.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, description: ?string}>  $details
     * @return array<int, Game>
     */
    private function upsertGames(array $items, array $details): array
    {
        $now = Carbon::now();
        $rows = [];
        $tagsByBggId = [];

        foreach ($items as $item) {
            $detail = $details[$item['bgg_id']] ?? null;
            $mechanics = $detail['mechanics'] ?? [];
            $categories = $detail['categories'] ?? [];
            $tags = [...$mechanics, ...$categories];

            // Independent signals, not a strict either/or (see the games
            // migration): a team-based game can be both cooperative (within
            // a team) and competitive (between teams), and BGG has no direct
            // "is competitive" flag, so it's inferred as "not solo" instead
            // of as the negation of cooperative - that negation previously
            // forced every cooperative game to is_competitive = false,
            // mislabeling semi-cooperative/team games and, on a failed BGG
            // detail fetch (empty $tags), forcing is_competitive = true with
            // no real signal either way.
            $isCooperative = $this->anyContains($tags, 'cooperative');
            $isSolo = $this->anyContains($tags, 'solo');

            $rows[] = [
                'id' => (string) Str::ulid(),
                'bgg_id' => $item['bgg_id'],
                'name' => $item['name'],
                'image_url' => $item['image_url'],
                'description' => $detail['description'] ?? null,
                'min_players' => $item['min_players'],
                'max_players' => $item['max_players'],
                'min_playtime_minutes' => $item['min_playtime_minutes'],
                'max_playtime_minutes' => $item['max_playtime_minutes'],
                'weight' => $detail['weight'] ?? null,
                'year_published' => $detail['year_published'] ?? null,
                'min_age' => $detail['min_age'] ?? null,
                'bgg_rank' => $detail['bgg_rank'] ?? null,
                'rating' => $detail['rating'] ?? null,
                'is_cooperative' => $isCooperative,
                'is_competitive' => ! $isSolo,
                'has_campaign' => $this->anyContains($tags, 'campaign')
                    || $this->anyContains($tags, 'legacy'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $tagsByBggId[$item['bgg_id']] = ['mechanics' => $mechanics, 'categories' => $categories];
        }

        Game::upsert($rows, uniqueBy: ['bgg_id'], update: [
            'name', 'image_url', 'description', 'min_players', 'max_players', 'min_playtime_minutes',
            'max_playtime_minutes', 'weight', 'year_published', 'min_age', 'bgg_rank',
            'rating', 'is_cooperative', 'is_competitive', 'has_campaign', 'updated_at',
        ]);

        $gamesByBggId = Game::whereIn('bgg_id', array_column($items, 'bgg_id'))->get()->keyBy('bgg_id')->all();

        $tagsByGameId = [];

        foreach ($tagsByBggId as $bggId => $tags) {
            $tagsByGameId[$gamesByBggId[$bggId]->id] = $tags;
        }

        $this->taxonomySyncer->syncManyFromBgg($tagsByGameId);

        return $gamesByBggId;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float, description: ?string}>  $details
     * @param  array<int, Game>  $gamesByBggId
     */
    private function linkExpansions(array $items, array $details, array $gamesByBggId): void
    {
        foreach ($items as $item) {
            // Already linked - either by a previous import, or by the user
            // correcting it by hand afterwards (BGG sometimes lists more
            // than one plausible base game for the same expansion, and the
            // pick below isn't always the one the user actually wants).
            // Re-running this on every import must not silently undo that.
            if ($gamesByBggId[$item['bgg_id']]->base_game_id !== null) {
                continue;
            }

            $baseBggIds = $details[$item['bgg_id']]['base_game_bgg_ids'] ?? [];

            // An expansion can list more than one base game (e.g. a deck
            // sold for both a game and a bundled reimplementation of it) -
            // link to whichever candidate is actually part of this
            // collection, instead of always the first/last one BGG reports,
            // which may not be owned at all.
            $ownedBaseBggIds = array_values(array_filter(
                $baseBggIds,
                fn (int $baseBggId): bool => isset($gamesByBggId[$baseBggId])
            ));

            if ($ownedBaseBggIds === []) {
                continue;
            }

            // More than one owned candidate happens too (e.g. an original
            // edition, a revised edition, and a freshly-announced future
            // one, all sharing the same expansion lineup on BGG) - prefer
            // the most established one (the better/lower BGG rank) instead
            // of whichever order BGG happens to report them in, which is
            // essentially arbitrary. Content keeps shipping for whichever
            // edition the community is actually playing, not for a
            // just-listed edition with barely any ratings yet - a missing
            // rank sorts last, same as a very high (bad) one would.
            usort(
                $ownedBaseBggIds,
                fn (int $a, int $b): int => ($gamesByBggId[$a]->bgg_rank ?? PHP_INT_MAX)
                    <=> ($gamesByBggId[$b]->bgg_rank ?? PHP_INT_MAX)
            );

            $gamesByBggId[$item['bgg_id']]->update(['base_game_id' => $gamesByBggId[$ownedBaseBggIds[0]]->id]);
        }
    }

    /**
     * Same bulk-upsert reasoning as upsertGames() above, for the user's
     * own owned/wishlist status per game.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, Game>  $gamesByBggId
     */
    private function syncUserCollection(BggImport $import, array $items, array $gamesByBggId): void
    {
        $now = Carbon::now();

        $rows = array_map(fn (array $item) => [
            'id' => (string) Str::ulid(),
            'user_id' => $import->user_id,
            'game_id' => $gamesByBggId[$item['bgg_id']]->id,
            'status' => $item['collection_status'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $items);

        UserGame::upsert($rows, uniqueBy: ['user_id', 'game_id'], update: ['status', 'updated_at']);
    }

    /**
     * Prefers 'owned' over 'wishlist' when two duplicate rows for the same
     * bgg_id disagree - owning something is the stronger signal - and
     * regardless of order: an owned row is never overwritten by a
     * later-seen wishlist duplicate of the same game.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function dedupeByBggId(array $items): array
    {
        $byBggId = [];

        foreach ($items as $item) {
            $bggId = $item['bgg_id'];

            if (! isset($byBggId[$bggId]) || $item['collection_status'] === 'owned') {
                $byBggId[$bggId] = $item;
            }
        }

        return array_values($byBggId);
    }

    /** @param  list<string>  $values */
    private function anyContains(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (str_contains(strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
