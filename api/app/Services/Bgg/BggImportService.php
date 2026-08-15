<?php

namespace App\Services\Bgg;

use App\Models\BggImport;
use App\Models\Game;
use App\Services\GameTaxonomySyncer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Timed and logged per phase (not just overall) because a real
        // import reported taking minutes both with and without a warm
        // /thing cache - identical duration either way means the actual
        // bottleneck likely isn't the detail-fetch cache this narrows down,
        // and only per-phase numbers from a real run can tell which of the
        // three (BGG's own /collection response time, the /thing fetch, or
        // the DB writes) it actually is.
        $collectionStartedAt = microtime(true);
        $collection = $this->client->fetchCollection($import->bgg_username);
        Log::info('BGG import: fetched collection', [
            'import_id' => $import->id,
            'status' => $collection['status'],
            'items' => isset($collection['items']) ? count($collection['items']) : null,
            'seconds' => round(microtime(true) - $collectionStartedAt, 2),
        ]);

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

        $items = $collection['items'];

        $detailsStartedAt = microtime(true);
        $details = $this->client->fetchGameDetails(array_column($items, 'bgg_id'));
        Log::info('BGG import: fetched game details', [
            'import_id' => $import->id,
            'games' => count($items),
            'seconds' => round(microtime(true) - $detailsStartedAt, 2),
        ]);

        $dbStartedAt = microtime(true);

        DB::transaction(function () use ($import, $items, $details) {
            $gamesByBggId = $this->upsertGames($items, $details);
            $this->linkExpansions($items, $details, $gamesByBggId);
            $this->syncUserCollection($import, $items, $gamesByBggId);

            $import->update(['status' => 'completed', 'imported_count' => count($items)]);
        });

        Log::info('BGG import: wrote games/taxonomy/collection to the database', [
            'import_id' => $import->id,
            'games' => count($items),
            'seconds' => round(microtime(true) - $dbStartedAt, 2),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float}>  $details
     * @return array<int, Game>
     */
    private function upsertGames(array $items, array $details): array
    {
        $gamesByBggId = [];

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

            $game = Game::updateOrCreate(
                ['bgg_id' => $item['bgg_id']],
                [
                    'name' => $item['name'],
                    'image_url' => $item['image_url'],
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
                ]
            );

            $this->taxonomySyncer->syncFromBgg($game, $mechanics, $categories);

            $gamesByBggId[$item['bgg_id']] = $game;
        }

        return $gamesByBggId;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_ids: list<int>, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float}>  $details
     * @param  array<int, Game>  $gamesByBggId
     */
    private function linkExpansions(array $items, array $details, array $gamesByBggId): void
    {
        foreach ($items as $item) {
            $baseBggIds = $details[$item['bgg_id']]['base_game_bgg_ids'] ?? [];

            // An expansion can list more than one base game (e.g. a deck
            // sold for both a game and a bundled reimplementation of it) -
            // link to whichever candidate is actually part of this
            // collection, instead of always the first/last one BGG reports,
            // which may not be owned at all.
            foreach ($baseBggIds as $baseBggId) {
                if (isset($gamesByBggId[$baseBggId])) {
                    $gamesByBggId[$item['bgg_id']]->update(['base_game_id' => $gamesByBggId[$baseBggId]->id]);

                    break;
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, Game>  $gamesByBggId
     */
    private function syncUserCollection(BggImport $import, array $items, array $gamesByBggId): void
    {
        foreach ($items as $item) {
            $game = $gamesByBggId[$item['bgg_id']];

            $import->user->games()->updateOrCreate(
                ['game_id' => $game->id],
                ['status' => $item['collection_status']]
            );
        }
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
