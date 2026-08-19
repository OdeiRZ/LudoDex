<?php

namespace App\Services\Bgg;

use App\Models\Game;
use App\Models\Play;
use App\Models\User;
use App\Services\GameTaxonomySyncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports a user's play history from BGG - unlike BggImportService (the
 * collection import, which polls a BggImport row because BGG's /collection
 * export is itself asynchronous), /plays answers synchronously, so this is
 * shaped like BggCsvImportService instead: one method, called directly from
 * the controller, no persisted "import" row at all.
 */
class BggPlaysImportService
{
    public function __construct(
        private readonly BggClient $client,
        private readonly GameTaxonomySyncer $taxonomySyncer,
    ) {}

    /**
     * @return array{status: 'ready'|'error', message?: string, imported_count?: int}
     */
    public function import(string $username, User $user): array
    {
        $result = $this->client->fetchPlays($username);

        if ($result['status'] === 'error') {
            return $result;
        }

        $plays = $result['plays'];
        $bggIds = array_values(array_unique(array_column($plays, 'bgg_id')));

        $gameIdsByBggId = Game::whereIn('bgg_id', $bggIds)->pluck('id', 'bgg_id')->all();
        $missingBggIds = array_values(array_diff($bggIds, array_keys($gameIdsByBggId)));

        $details = $missingBggIds !== [] ? $this->client->fetchGameDetails($missingBggIds) : [];

        DB::transaction(function () use ($plays, $missingBggIds, $details, &$gameIdsByBggId, $user) {
            if ($missingBggIds !== []) {
                // Array union (+), not spread ([...$a, ...$b]) - both are
                // keyed by bgg_id (an int), and spreading an int-keyed array
                // discards those keys and re-indexes from 0 instead of
                // preserving them the way a string-keyed array would.
                $gameIdsByBggId += $this->createMissingGames($plays, $missingBggIds, $details);
            }

            $this->upsertPlays($plays, $gameIdsByBggId, $user);
        });

        return ['status' => 'ready', 'imported_count' => count($plays)];
    }

    /**
     * Creates a Game row for every bgg_id a play references that isn't in
     * the local catalog yet - name always comes from the play's own <item>
     * (present even when the /thing fetch for that id failed), the rest
     * only when $details has it, same graceful-degradation as
     * BggImportService::upsertGames().
     *
     * @param  list<array{bgg_id: int, name: string}>  $plays
     * @param  list<int>  $missingBggIds
     * @param  array<int, array<string, mixed>>  $details
     * @return array<int, string> bgg_id => game id
     */
    private function createMissingGames(array $plays, array $missingBggIds, array $details): array
    {
        $missing = array_fill_keys($missingBggIds, true);
        $nameByBggId = [];

        foreach ($plays as $play) {
            if (isset($missing[$play['bgg_id']]) && ! isset($nameByBggId[$play['bgg_id']])) {
                $nameByBggId[$play['bgg_id']] = $play['name'];
            }
        }

        $now = Carbon::now();
        $rows = [];
        $tagsByBggId = [];

        foreach ($missingBggIds as $bggId) {
            $detail = $details[$bggId] ?? null;
            $mechanics = $detail['mechanics'] ?? [];
            $categories = $detail['categories'] ?? [];
            $mode = Game::inferModeFromTags($mechanics, $categories);

            $rows[] = [
                'id' => (string) Str::ulid(),
                'bgg_id' => $bggId,
                'name' => $nameByBggId[$bggId],
                'image_url' => $detail['image_url'] ?? null,
                'description' => $detail['description'] ?? null,
                'min_players' => $detail['min_players'] ?? null,
                'max_players' => $detail['max_players'] ?? null,
                'min_playtime_minutes' => $detail['min_playtime_minutes'] ?? null,
                'max_playtime_minutes' => $detail['max_playtime_minutes'] ?? null,
                'weight' => $detail['weight'] ?? null,
                'year_published' => $detail['year_published'] ?? null,
                'min_age' => $detail['min_age'] ?? null,
                'bgg_rank' => $detail['bgg_rank'] ?? null,
                'rating' => $detail['rating'] ?? null,
                'is_cooperative' => $mode['is_cooperative'],
                'is_competitive' => $mode['is_competitive'],
                'has_campaign' => $mode['has_campaign'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $tagsByBggId[$bggId] = ['mechanics' => $mechanics, 'categories' => $categories];
        }

        Game::upsert($rows, uniqueBy: ['bgg_id'], update: [
            'name', 'image_url', 'description', 'min_players', 'max_players', 'min_playtime_minutes',
            'max_playtime_minutes', 'weight', 'year_published', 'min_age', 'bgg_rank',
            'rating', 'is_cooperative', 'is_competitive', 'has_campaign', 'updated_at',
        ]);

        $gameIdsByBggId = Game::whereIn('bgg_id', $missingBggIds)->pluck('id', 'bgg_id')->all();

        $tagsByGameId = [];

        foreach ($tagsByBggId as $bggId => $tags) {
            $tagsByGameId[$gameIdsByBggId[$bggId]] = $tags;
        }

        $this->taxonomySyncer->syncManyFromBgg($tagsByGameId);

        return $gameIdsByBggId;
    }

    /**
     * One batched upsert for every play instead of an updateOrCreate() per
     * play - a real play history is easily thousands of rows, and this app
     * already had to fix exactly this kind of per-row-loop performance
     * problem once for the collection import (see BggImportService).
     *
     * @param  list<array{bgg_play_id: int, bgg_id: int, played_at: string, quantity: int, duration_minutes: ?int}>  $plays
     * @param  array<int, string>  $gameIdsByBggId
     */
    private function upsertPlays(array $plays, array $gameIdsByBggId, User $user): void
    {
        $now = Carbon::now();

        $rows = array_map(fn (array $play) => [
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'game_id' => $gameIdsByBggId[$play['bgg_id']],
            'bgg_play_id' => $play['bgg_play_id'],
            'played_at' => $play['played_at'],
            'quantity' => $play['quantity'],
            'duration_minutes' => $play['duration_minutes'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $plays);

        if ($rows === []) {
            return;
        }

        Play::upsert($rows, uniqueBy: ['user_id', 'bgg_play_id'], update: [
            'game_id', 'played_at', 'quantity', 'duration_minutes', 'updated_at',
        ]);
    }
}
