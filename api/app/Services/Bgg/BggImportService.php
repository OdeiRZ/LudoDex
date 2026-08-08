<?php

namespace App\Services\Bgg;

use App\Models\BggImport;
use App\Models\Game;
use App\Services\GameTaxonomySyncer;
use Illuminate\Support\Facades\DB;

class BggImportService
{
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
            $import->update(['status' => 'failed', 'error_message' => $collection['message']]);

            return;
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
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int}>  $details
     * @return array<int, Game>
     */
    private function upsertGames(array $items, array $details): array
    {
        $gamesByBggId = [];

        foreach ($items as $item) {
            $detail = $details[$item['bgg_id']] ?? null;
            $mechanics = $detail['mechanics'] ?? [];
            $categories = $detail['categories'] ?? [];
            $isCooperative = $this->anyContains([...$mechanics, ...$categories], 'cooperative');

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
                    'is_cooperative' => $isCooperative,
                    'is_competitive' => ! $isCooperative,
                    'has_campaign' => $this->anyContains([...$mechanics, ...$categories], 'campaign')
                        || $this->anyContains([...$mechanics, ...$categories], 'legacy'),
                ]
            );

            $this->taxonomySyncer->sync($game, $mechanics, $categories);

            $gamesByBggId[$item['bgg_id']] = $game;
        }

        return $gamesByBggId;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array{mechanics: list<string>, categories: list<string>, weight: ?float, base_game_bgg_id: ?int}>  $details
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
