<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Personal-use, run-from-local-only: keeps description_es in sync between
 * this local database and production, in both directions, without ever
 * overwriting an existing translation on either side - same "fill gaps
 * only" rule GameTranslationBackfillController already enforces server-side
 * for the local-to-prod direction. Replaces the manual process this used to
 * be (pulling auth tokens out of open browser tabs to call the backfill
 * endpoint by hand) with one command.
 */
class SyncTranslations extends Command
{
    protected $signature = 'translations:sync {--dry-run : Only report what would change, without writing anything}';

    protected $description = 'Copy Spanish game descriptions between this local database and production, filling gaps on both sides';

    public function handle(): int
    {
        $apiUrl = config('prod_sync.api_url');
        $apiToken = config('prod_sync.api_token');

        if (blank($apiUrl) || blank($apiToken)) {
            $this->error('PROD_API_URL and PROD_API_TOKEN must both be set in .env first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $local = $this->translatedGames();
        $this->info(count($local).' translated game(s) found locally.');

        $remote = $this->fetchRemoteTranslatedGames($apiUrl, $apiToken);

        if ($remote === null) {
            return self::FAILURE;
        }

        $this->info(count($remote).' translated game(s) found on production.');

        $pushed = $this->pushToProduction($apiUrl, $apiToken, $local, $remote, $dryRun);
        $pulled = $this->pullFromProduction($remote, $dryRun);

        $verb = $dryRun ? 'would be filled' : 'filled';
        $this->info("Done - {$pushed} gap(s) on production {$verb}, {$pulled} gap(s) locally {$verb}.");

        return self::SUCCESS;
    }

    /** @return array<int, array{bgg_id: int, description_es: string}> */
    private function translatedGames(): array
    {
        return Game::query()
            ->whereNotNull('bgg_id')
            ->whereNotNull('description_es')
            ->get(['bgg_id', 'description_es'])
            ->map(fn (Game $game) => ['bgg_id' => $game->bgg_id, 'description_es' => $game->description_es])
            ->all();
    }

    /** @return array<int, array{bgg_id: int, description_es: string}>|null */
    private function fetchRemoteTranslatedGames(string $apiUrl, string $apiToken): ?array
    {
        $response = Http::withToken($apiToken)->get("{$apiUrl}/games");

        if ($response->failed()) {
            $this->error("Could not fetch production's own game list (HTTP {$response->status()}).");

            return null;
        }

        return collect($response->json('data'))
            ->pluck('game')
            ->filter(fn (array $game) => $game['bgg_id'] !== null && $game['description_es'] !== null)
            ->map(fn (array $game) => ['bgg_id' => $game['bgg_id'], 'description_es' => $game['description_es']])
            ->all();
    }

    /**
     * @param  array<int, array{bgg_id: int, description_es: string}>  $local
     * @param  array<int, array{bgg_id: int, description_es: string}>  $remote
     */
    private function pushToProduction(string $apiUrl, string $apiToken, array $local, array $remote, bool $dryRun): int
    {
        $remoteBggIds = collect($remote)->pluck('bgg_id')->all();
        $missingOnProd = collect($local)->reject(fn (array $game) => in_array($game['bgg_id'], $remoteBggIds, true))->values();

        if ($missingOnProd->isEmpty()) {
            return 0;
        }

        if ($dryRun) {
            return $missingOnProd->count();
        }

        // Same endpoint the manual process already used - it only ever
        // fills a null description_es, so this can't clobber a translation
        // production already has (e.g. one made there since the last sync).
        $response = Http::withToken($apiToken)
            ->post("{$apiUrl}/games/backfill-translations", ['games' => $missingOnProd->all()]);

        if ($response->failed()) {
            $this->error("Pushing to production failed (HTTP {$response->status()}).");

            return 0;
        }

        return (int) $response->json('updated');
    }

    /** @param  array<int, array{bgg_id: int, description_es: string}>  $remote */
    private function pullFromProduction(array $remote, bool $dryRun): int
    {
        $updated = 0;

        foreach ($remote as $game) {
            if ($dryRun) {
                $updated += Game::query()
                    ->where('bgg_id', $game['bgg_id'])
                    ->whereNull('description_es')
                    ->count();

                continue;
            }

            $updated += Game::query()
                ->where('bgg_id', $game['bgg_id'])
                ->whereNull('description_es')
                ->update(['description_es' => $game['description_es']]);
        }

        return $updated;
    }
}
