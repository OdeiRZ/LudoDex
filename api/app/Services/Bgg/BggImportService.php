<?php

namespace App\Services\Bgg;

use App\Models\BggImport;
use App\Models\Game;
use App\Services\GameTaxonomySyncer;
use Illuminate\Support\Facades\DB;

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

        $items = $collection['items'];
        $details = $this->client->fetchGameDetails(array_column($items, 'bgg_id'));

        DB::transaction(function () use ($import, $items, $details) {
            $gamesByBggId = $this->upsertGames($items, $details);
            $this->linkExpansions($items, $details, $gamesByBggId);
            $this->syncUserCollection($import, $items, $gamesByBggId);

            $import->update(['status' => 'completed', 'imported_count' => count($items)]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float}>  $details
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
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int, year_published: ?int, min_age: ?string, bgg_rank: ?int, rating: ?float}>  $details
     * @param  array<int, Game>  $gamesByBggId
     */
    private function linkExpansions(array $items, array $details, array $gamesByBggId): void
    {
        foreach ($items as $item) {
            $baseBggId = $details[$item['bgg_id']]['base_game_bgg_id'] ?? null;

            if ($baseBggId !== null && isset($gamesByBggId[$baseBggId])) {
                $gamesByBggId[$item['bgg_id']]->update(['base_game_id' => $gamesByBggId[$baseBggId]->id]);
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
