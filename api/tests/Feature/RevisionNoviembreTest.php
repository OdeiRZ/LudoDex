<?php

use App\Models\BggImport;
use App\Models\Game;
use App\Models\Mechanic;
use App\Models\Play;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Correcciones de la revisión de noviembre: límites de las columnas (en
 * PostgreSQL desbordan y dan 500), importaciones de BGG, contadores de límite
 * de peticiones y ids de ruta.
 */
it('accepts a max without a min for players and playtime, and still checks the order when both are given', function () {
    actingAsUser();

    $this->postJson('/api/games', ['name' => 'Solo máximo', 'status' => 'owned', 'max_players' => 4, 'max_playtime_minutes' => 60])
        ->assertCreated();

    $this->postJson('/api/games', ['name' => 'Al revés', 'status' => 'owned', 'min_players' => 4, 'max_players' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_players');
});

it('rejects values that do not fit the columns instead of failing with a 500 in PostgreSQL', function (string $campo, mixed $valor) {
    actingAsUser();

    $this->postJson('/api/games', ['name' => 'Desbordado', 'status' => 'owned', $campo => $valor])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($campo);
})->with([
    'image_url de más de 255' => ['image_url', 'https://example.com/'.str_repeat('a', 300)],
    'bgg_id fuera de un integer' => ['bgg_id', 3000000000],
    'bgg_rank fuera de un integer' => ['bgg_rank', 3000000000],
    'duración fuera de un smallint' => ['max_playtime_minutes', 40000],
]);

it('answers the same when blocking an id that does not exist, and stores nothing', function () {
    $me = actingAsUser();
    $real = User::factory()->create();

    $existing = $this->postJson('/api/friends/blocks', ['user_id' => $real->id]);
    $missing = $this->postJson('/api/friends/blocks', ['user_id' => 999999]);

    $existing->assertCreated();
    $missing->assertCreated();
    expect(UserBlock::where('blocker_id', $me->id)->count())->toBe(1);
});

it('does not share the rate limit between different routes', function () {
    $user = actingAsUser();
    $import = BggImport::factory()->for($user)->create(['status' => 'completed']);

    // El sondeo de importación (cada 3 s desde la web) no puede gastar el cupo de
    // las rutas más estrictas: antes compartían un único contador por usuario.
    for ($i = 0; $i < 8; $i++) {
        $this->getJson("/api/bgg-imports/{$import->id}")->assertOk();
    }

    $this->getJson('/api/friends/search?email=alguien@example.com')->assertStatus(200);
    $this->postJson('/api/bgg-plays-imports', ['bgg_username' => ''])->assertUnprocessable();
});

it('returns 404, not a 500, for a non numeric id in the route', function (string $url) {
    actingAsUser();

    $this->getJson($url)->assertNotFound();
})->with([
    '/api/friends/abc/games',
    '/api/friends/abc/plays',
    '/api/friends/abc/plays/stats',
    '/api/bgg-lookup/games/abc',
]);

it('keeps the weight, the mode and the tags of a game when BGG returned no detail for it', function () {
    $user = actingAsUser();
    $game = Game::factory()->create([
        'bgg_id' => 13,
        'name' => 'Catan',
        'weight' => 2.3,
        'rating' => 7.1,
        'is_cooperative' => true,
        'is_competitive' => false,
    ]);
    $game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Trading']));

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), '/collection') && str_contains($request->url(), 'subtype=boardgame&')) {
            return Http::response('<?xml version="1.0" encoding="utf-8"?><items totalitems="1"><item objecttype="thing" objectid="13" subtype="boardgame"><name sortindex="1">Catan</name><image>https://example.com/c.jpg</image><status own="1" wishlist="0"/></item></items>');
        }

        if (str_contains($request->url(), '/collection')) {
            return Http::response('<?xml version="1.0" encoding="utf-8"?><items totalitems="0"></items>');
        }

        // /thing falla: BGG no devuelve el detalle de ningún juego.
        return Http::response('', 500);
    });

    $this->postJson('/api/bgg-imports', ['bgg_username' => 'odei'])->assertCreated();

    $game->refresh();
    expect($game->weight)->toBe(2.3)
        ->and($game->rating)->toBe(7.1)
        ->and($game->is_cooperative)->toBeTrue()
        ->and($game->mechanics()->pluck('name')->all())->toBe(['Trading']);
});

function playsXml(array $plays): string
{
    $rows = array_map(
        fn (array $p) => '<play id="'.$p[0].'" date="'.$p[1].'" quantity="'.$p[2].'" length="'.$p[3].'"><item name="Catan" objectid="13" objecttype="thing"/></play>',
        $plays,
    );

    return '<?xml version="1.0" encoding="utf-8"?><plays total="'.count($plays).'" page="1">'.implode('', $rows).'</plays>';
}

it('imports plays even when one is repeated, has no real date or has absurd numbers', function () {
    Sleep::fake();
    $user = actingAsUser();
    Game::factory()->create(['bgg_id' => 13, 'name' => 'Catan']);

    Http::fake(fn () => Http::response(playsXml([
        [1, '2026-01-01', 2, 60],
        [1, '2026-01-01', 2, 60],      // la misma partida en dos páginas
        [2, '0000-00-00', 1, 30],      // fecha que no existe
        [3, '2026-01-03', 1, 99999],   // duración que no cabe en un smallint
        [4, '2026-02-30', 1, 30],      // 30 de febrero
    ])));

    $this->postJson('/api/bgg-plays-imports', ['bgg_username' => 'odei'])->assertOk();

    expect(Play::where('user_id', $user->id)->count())->toBe(2);
    expect(Play::where('bgg_play_id', 3)->first()->duration_minutes)->toBeNull();
});
