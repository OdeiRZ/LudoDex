<?php

use App\Models\Game;
use App\Models\Play;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

// BggClient::fetchPlays() paces itself between pages - fake it so the
// multi-page test below doesn't actually wait a real second.
beforeEach(function () {
    Sleep::fake();
});

function playXmlRow(int $id, int $bggId, string $name, string $date, ?string $quantity = null, ?string $length = null): string
{
    $quantityAttr = $quantity !== null ? " quantity=\"{$quantity}\"" : '';
    $lengthAttr = $length !== null ? " length=\"{$length}\"" : '';

    return <<<XML
    <play id="{$id}" date="{$date}"{$quantityAttr}{$lengthAttr}>
        <item name="{$name}" objectid="{$bggId}" objecttype="thing"/>
    </play>
    XML;
}

function fakePlaysOf(string $username, string $innerXml): void
{
    Http::fake(function (ClientRequest $request) use ($innerXml) {
        if (str_contains($request->url(), '/plays')) {
            return Http::response('<?xml version="1.0" encoding="utf-8"?><plays total="1" page="1">'.$innerXml.'</plays>');
        }

        return Http::response('should not be called', 500);
    });
}

function postPlaysImport(string $username): TestResponse
{
    return test()->withHeader('Accept', 'application/json')
        ->post('/api/bgg-plays-imports', ['bgg_username' => $username]);
}

it('rejects the request without a bgg_username', function () {
    actingAsUser();

    postPlaysImport('')->assertUnprocessable();
});

it('rejects unauthenticated requests', function () {
    postPlaysImport('odei')->assertUnauthorized();
});

it('imports plays for a game already in the local catalog', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13, 'name' => 'Catan']);

    fakePlaysOf('odei', playXmlRow(1001, 13, 'Catan', '2026-01-01', quantity: '2', length: '60'));

    $response = postPlaysImport('odei')->assertOk();

    $response->assertJsonPath('data.imported_count', 1);

    $play = Play::where('bgg_play_id', 1001)->first();
    expect($play)->not->toBeNull()
        ->and($play->user_id)->toBe($user->id)
        ->and($play->game_id)->toBe($game->id)
        ->and($play->played_at->toDateString())->toBe('2026-01-01')
        ->and($play->quantity)->toBe(2)
        ->and($play->duration_minutes)->toBe(60);
});

it('requests every page until BGG returns fewer than 100 plays', function () {
    actingAsUser();
    Game::factory()->create(['bgg_id' => 13]);

    Http::fake(function (ClientRequest $request) {
        if (! str_contains($request->url(), '/plays')) {
            return Http::response('should not be called', 500);
        }

        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];
        $count = $page === 1 ? 100 : 1;
        $plays = '';

        for ($i = 0; $i < $count; $i++) {
            $plays .= playXmlRow(($page * 1000) + $i, 13, 'Catan', '2026-01-01');
        }

        return Http::response('<?xml version="1.0" encoding="utf-8"?><plays total="101" page="'.$page.'">'.$plays.'</plays>');
    });

    $response = postPlaysImport('odei')->assertOk();

    $response->assertJsonPath('data.imported_count', 101);
    Http::assertSentCount(2);
    expect(Play::count())->toBe(101);
});

it('auto-creates a game that is not in the local catalog yet', function () {
    actingAsUser();

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), '/plays')) {
            return Http::response(
                '<?xml version="1.0" encoding="utf-8"?><plays total="1" page="1">'
                .playXmlRow(1001, 999, 'Brand New Game', '2026-01-01')
                .'</plays>'
            );
        }

        if (str_contains($request->url(), '/thing')) {
            return Http::response(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <items>
                <item type="boardgame" id="999">
                    <name type="primary" sortindex="1" value="Brand New Game"/>
                    <yearpublished value="2024"/>
                    <link type="boardgamemechanic" id="1" value="Cooperative Game"/>
                </item>
            </items>
            XML);
        }

        return Http::response('should not be called', 500);
    });

    $response = postPlaysImport('odei')->assertOk();
    $response->assertJsonPath('data.imported_count', 1);

    $game = Game::where('bgg_id', 999)->first();
    expect($game)->not->toBeNull()
        ->and($game->name)->toBe('Brand New Game')
        ->and($game->year_published)->toBe(2024)
        ->and($game->is_cooperative)->toBeTrue();

    expect(Play::where('game_id', $game->id)->exists())->toBeTrue();
});

it('still creates the game from the play item name when the /thing fetch for it fails', function () {
    actingAsUser();

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), '/plays')) {
            return Http::response(
                '<?xml version="1.0" encoding="utf-8"?><plays total="1" page="1">'
                .playXmlRow(1001, 999, 'Unreachable Detail Game', '2026-01-01')
                .'</plays>'
            );
        }

        if (str_contains($request->url(), '/thing')) {
            return Http::response('', 500);
        }

        return Http::response('should not be called', 500);
    });

    postPlaysImport('odei')->assertOk();

    $game = Game::where('bgg_id', 999)->first();
    expect($game)->not->toBeNull()->and($game->name)->toBe('Unreachable Detail Game');
});

it('upserts rather than duplicates when the same plays are imported twice', function () {
    actingAsUser();
    Game::factory()->create(['bgg_id' => 13]);

    fakePlaysOf('odei', playXmlRow(1001, 13, 'Catan', '2026-01-01', quantity: '1', length: '30'));

    postPlaysImport('odei')->assertOk();
    postPlaysImport('odei')->assertOk();

    expect(Play::count())->toBe(1);
});

it('parses a batched multi-play entry via quantity, and a missing length as no duration', function () {
    actingAsUser();
    Game::factory()->create(['bgg_id' => 13]);

    fakePlaysOf('odei', playXmlRow(1001, 13, 'Catan', '2026-01-01', quantity: '3'));

    postPlaysImport('odei')->assertOk();

    $play = Play::where('bgg_play_id', 1001)->first();
    expect($play->quantity)->toBe(3)->and($play->duration_minutes)->toBeNull();
});

it('sends no mindate on a user\'s first-ever import (nothing stored yet to filter from)', function () {
    actingAsUser();
    Game::factory()->create(['bgg_id' => 13]);

    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($query)->not->toHaveKey('mindate');

        return Http::response(
            '<?xml version="1.0" encoding="utf-8"?><plays total="1" page="1">'
            .playXmlRow(1001, 13, 'Catan', '2026-01-01')
            .'</plays>'
        );
    });

    postPlaysImport('odei')->assertOk();
});

it('sends mindate as the latest stored play minus a week\'s overlap on a reimport', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13]);
    Play::factory()->for($user)->for($game)->create(['played_at' => '2026-02-10']);

    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($query['mindate'] ?? null)->toBe('2026-02-03');

        return Http::response(
            '<?xml version="1.0" encoding="utf-8"?><plays total="1" page="1">'
            .playXmlRow(1002, 13, 'Catan', '2026-02-12')
            .'</plays>'
        );
    });

    postPlaysImport('odei')->assertOk();

    expect(Play::count())->toBe(2);
});

it('returns an error without importing anything when no BGG application token is configured', function () {
    actingAsUser();
    config(['bgg.application_token' => null]);

    Http::fake(fn () => Http::response('should not be called', 500));

    postPlaysImport('odei')->assertUnprocessable();

    Http::assertNothingSent();
    expect(Play::count())->toBe(0);
});
