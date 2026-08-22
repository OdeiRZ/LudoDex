<?php

use App\Models\Game;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['prod_sync.api_url' => 'https://prod.example/api', 'prod_sync.api_token' => 'fake-token']);
});

function fakeRemoteGames(array $games): void
{
    Http::fake([
        'https://prod.example/api/games' => Http::response([
            'data' => array_map(fn (array $game) => ['game' => $game], $games),
        ]),
        'https://prod.example/api/games/backfill-translations' => Http::response(['updated' => 1]),
    ]);
}

it('fails clearly when PROD_API_URL/PROD_API_TOKEN are not configured', function () {
    config(['prod_sync.api_url' => null, 'prod_sync.api_token' => null]);
    Http::fake();

    $this->artisan('translations:sync')->assertExitCode(1);

    Http::assertNothingSent();
});

it('pushes local-only translations to production via the backfill endpoint', function () {
    Game::factory()->create(['bgg_id' => 111, 'description_es' => 'Texto local.']);
    fakeRemoteGames([]); // production has no translations at all yet

    $this->artisan('translations:sync')->assertExitCode(0);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://prod.example/api/games/backfill-translations'
            && $request->hasHeader('Authorization', 'Bearer fake-token')
            && $request['games'] === [['bgg_id' => 111, 'description_es' => 'Texto local.']];
    });
});

it('pulls production-only translations into the local database, filling only null gaps', function () {
    $game = Game::factory()->create(['bgg_id' => 222, 'description_es' => null]);
    fakeRemoteGames([
        ['id' => 'g1', 'bgg_id' => 222, 'name' => 'Some Game', 'image_url' => null, 'description_es' => 'Texto de producción.'],
    ]);

    $this->artisan('translations:sync')->assertExitCode(0);

    expect($game->fresh()->description_es)->toBe('Texto de producción.');
    // Nothing local was missing on production (222 is already there), so
    // the push direction has nothing to send.
    Http::assertNotSent(fn ($request) => $request->url() === 'https://prod.example/api/games/backfill-translations');
});

it('never overwrites a translation that already exists locally, even if production has a different one', function () {
    $game = Game::factory()->create(['bgg_id' => 333, 'description_es' => 'Texto local existente.']);
    fakeRemoteGames([
        ['id' => 'g1', 'bgg_id' => 333, 'name' => 'Some Game', 'image_url' => null, 'description_es' => 'Texto distinto en producción.'],
    ]);

    $this->artisan('translations:sync')->assertExitCode(0);

    expect($game->fresh()->description_es)->toBe('Texto local existente.');
});

it('does not write anything in --dry-run, only reports what would change', function () {
    $game = Game::factory()->create(['bgg_id' => 444, 'description_es' => null]);
    fakeRemoteGames([
        ['id' => 'g1', 'bgg_id' => 444, 'name' => 'Some Game', 'image_url' => null, 'description_es' => 'Texto de producción.'],
    ]);

    $this->artisan('translations:sync', ['--dry-run' => true])->assertExitCode(0);

    expect($game->fresh()->description_es)->toBeNull();
    Http::assertNotSent(fn ($request) => $request->url() === 'https://prod.example/api/games/backfill-translations');
});

it('fails cleanly when fetching production\'s own game list fails', function () {
    Http::fake(['https://prod.example/api/games' => Http::response('server error', 500)]);

    $this->artisan('translations:sync')->assertExitCode(1);
});
