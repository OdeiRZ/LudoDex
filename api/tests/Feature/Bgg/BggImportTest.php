<?php

use App\Models\BggImport;
use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

function collectionXml(string $subtype, ?array $item): string
{
    if ($item === null) {
        return '<?xml version="1.0" encoding="utf-8"?><items totalitems="0"></items>';
    }

    return <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <items totalitems="1">
        <item objecttype="thing" objectid="{$item['id']}" subtype="{$subtype}" collid="1">
            <name sortindex="1">{$item['name']}</name>
            <image>{$item['image']}</image>
            <status own="{$item['own']}" wishlist="{$item['wishlist']}" prevowned="0" fortrade="0" want="0" wanttoplay="0" wanttobuy="0" wishlistpriority="0" preordered="0" lastmodified="2020-01-01 00:00:00"/>
            <numplays>0</numplays>
            <stats minplayers="3" maxplayers="4" minplaytime="60" maxplaytime="120" playingtime="120" numowned="1"/>
        </item>
    </items>
    XML;
}

function thingXml(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
            <yearpublished value="1995"/>
            <minage value="10"/>
            <link type="boardgamemechanic" id="2027" value="Trading"/>
            <link type="boardgamemechanic" id="2072" value="Dice Rolling"/>
            <link type="boardgamecategory" id="1078" value="Negotiation"/>
            <statistics page="1">
                <ratings>
                    <average value="7.13"/>
                    <ranks>
                        <rank type="subtype" id="1" name="boardgame" friendlyname="Board Game Rank" value="343"/>
                    </ranks>
                    <averageweight value="2.32"/>
                </ratings>
            </statistics>
        </item>
        <item type="boardgameexpansion" id="9209">
            <name type="primary" sortindex="1" value="Catan: Seafarers"/>
            <link type="boardgameexpansion" id="13" value="Catan" inbound="true"/>
            <link type="boardgamemechanic" id="2027" value="Trading"/>
            <statistics page="1">
                <ratings>
                    <averageweight value="2.7"/>
                </ratings>
            </statistics>
        </item>
    </items>
    XML;
}

function fakeSuccessfulBggImport(): void
{
    Http::fake(function (ClientRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/collection') && str_contains($url, 'subtype=boardgame&')) {
            return Http::response(collectionXml('boardgame', [
                'id' => 13, 'name' => 'Catan', 'image' => 'https://example.com/catan.jpg',
                'own' => 1, 'wishlist' => 0,
            ]));
        }

        if (str_contains($url, '/collection') && str_contains($url, 'subtype=boardgameexpansion')) {
            return Http::response(collectionXml('boardgameexpansion', [
                'id' => 9209, 'name' => 'Catan: Seafarers', 'image' => 'https://example.com/seafarers.jpg',
                'own' => 1, 'wishlist' => 0,
            ]));
        }

        if (str_contains($url, '/thing')) {
            return Http::response(thingXml());
        }

        return Http::response('', 404);
    });
}

it('fails immediately when no BGG application token is configured', function () {
    actingAsUser();
    config(['bgg.application_token' => null]);

    Http::fake(fn () => Http::response('should not be called', 500));

    $response = $this->postJson('/api/bgg-imports', ['bgg_username' => 'someuser']);

    $response->assertCreated()->assertJsonPath('data.status', 'failed');
    Http::assertNothingSent();
});

it('starts an import and leaves it pending while BGG queues the export', function () {
    actingAsUser();

    Http::fake(fn () => Http::response('', 202));

    $response = $this->postJson('/api/bgg-imports', ['bgg_username' => 'someuser']);

    $response->assertCreated()->assertJsonPath('data.status', 'pending');
});

it('fails the import when the BGG username does not exist', function () {
    actingAsUser();

    Http::fake(fn () => Http::response(
        '<?xml version="1.0"?><errors><error><message>Invalid username specified</message></error></errors>'
    ));

    $response = $this->postJson('/api/bgg-imports', ['bgg_username' => 'nobody']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_message', 'Invalid username specified');
});

it('imports owned games and expansions, linking the expansion to its base game', function () {
    $user = actingAsUser();
    fakeSuccessfulBggImport();

    $response = $this->postJson('/api/bgg-imports', ['bgg_username' => 'odei']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.imported_count', 2);

    $catan = Game::where('bgg_id', 13)->first();
    $seafarers = Game::where('bgg_id', 9209)->first();

    expect($catan)->not->toBeNull();
    expect($seafarers)->not->toBeNull();
    expect($seafarers->base_game_id)->toBe($catan->id);
    expect($catan->mechanics->pluck('name')->all())->toEqual(['Trading', 'Dice Rolling']);
    expect($catan->categories->pluck('name')->all())->toEqual(['Negotiation']);
    expect($user->games()->count())->toBe(2);

    // The same /thing call already fetched for mechanics/categories/weight
    // also carries year, rank and rating - no extra request needed to
    // populate these.
    expect($catan->year_published)->toBe(1995)
        ->and($catan->min_age)->toBe('10+')
        ->and($catan->bgg_rank)->toBe(343)
        ->and($catan->rating)->toBe(7.13);

    // Seafarers' fixture has no yearpublished/minage/ranks/average at all -
    // confirms those stay null instead of crashing when a game genuinely
    // has none of this data.
    expect($seafarers->year_published)->toBeNull()
        ->and($seafarers->min_age)->toBeNull()
        ->and($seafarers->bgg_rank)->toBeNull()
        ->and($seafarers->rating)->toBeNull();
});

it('keeps a manually-added mechanic/category after re-importing from BGG, instead of wiping it', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['bgg_id' => 13, 'name' => 'Catan']);
    $catan->mechanics()->attach(Mechanic::factory()->create(['name' => 'Homebrew Mechanic']));
    $catan->categories()->attach(Category::factory()->create(['name' => 'Homebrew Category']));

    fakeSuccessfulBggImport();

    $this->postJson('/api/bgg-imports', ['bgg_username' => 'odei'])->assertCreated();

    $mechanicNames = $catan->refresh()->mechanics->pluck('name')->sort()->values()->all();
    $categoryNames = $catan->categories->pluck('name')->sort()->values()->all();

    // BGG's own mechanics/categories for Catan (see thingXml()) are added
    // alongside the homebrew ones, not instead of them.
    expect($mechanicNames)->toBe(['Dice Rolling', 'Homebrew Mechanic', 'Trading'])
        ->and($categoryNames)->toBe(['Homebrew Category', 'Negotiation']);
});

it('marks the collection status (owned vs wishlist) correctly', function () {
    $user = actingAsUser();

    Http::fake(function (ClientRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/collection') && str_contains($url, 'subtype=boardgame&')) {
            return Http::response(collectionXml('boardgame', [
                'id' => 13, 'name' => 'Catan', 'image' => 'https://example.com/catan.jpg',
                'own' => 0, 'wishlist' => 1,
            ]));
        }

        if (str_contains($url, '/collection')) {
            return Http::response(collectionXml('boardgameexpansion', null));
        }

        return Http::response(thingXml());
    });

    $this->postJson('/api/bgg-imports', ['bgg_username' => 'odei'])->assertCreated();

    expect($user->games()->first()->status)->toBe('wishlist');
});

it('treats cooperative and competitive as independent, not opposite, flags', function () {
    $user = actingAsUser();

    Http::fake(function (ClientRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/collection') && str_contains($url, 'subtype=boardgame&')) {
            return Http::response(collectionXml('boardgame', [
                'id' => 30549, 'name' => 'Pandemic', 'image' => 'https://example.com/pandemic.jpg',
                'own' => 1, 'wishlist' => 0,
            ]));
        }

        if (str_contains($url, '/collection')) {
            return Http::response(collectionXml('boardgameexpansion', null));
        }

        return Http::response(<<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <items>
            <item type="boardgame" id="30549">
                <name type="primary" sortindex="1" value="Pandemic"/>
                <link type="boardgamemechanic" id="2015" value="Cooperative Game"/>
            </item>
        </items>
        XML);
    });

    $this->postJson('/api/bgg-imports', ['bgg_username' => 'odei'])->assertCreated();

    $pandemic = Game::where('bgg_id', 30549)->first();

    // Team-based cooperative games are still competitive against the game
    // itself/other teams - is_competitive must not be forced to the
    // opposite of is_cooperative (see BggImportService::upsertGames).
    expect($pandemic->is_cooperative)->toBeTrue();
    expect($pandemic->is_competitive)->toBeTrue();
});

it('does not reprocess an import that already finished', function () {
    $user = actingAsUser();
    $import = BggImport::factory()->for($user)->create(['status' => 'completed', 'imported_count' => 2]);

    Http::fake(fn () => Http::response('should not be called', 500));

    $this->getJson("/api/bgg-imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    Http::assertNothingSent();
});

it('forbids polling another user\'s import', function () {
    actingAsUser();
    $otherUser = User::factory()->create();
    $import = BggImport::factory()->for($otherUser)->create();

    $this->getJson("/api/bgg-imports/{$import->id}")->assertForbidden();
});

it('stays pending and counts a transient BGG failure instead of failing immediately', function () {
    $user = actingAsUser();
    $import = BggImport::factory()->for($user)->create(['bgg_username' => 'odei']);

    Http::fake(fn () => Http::response('', 500));

    $this->getJson("/api/bgg-imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($import->refresh()->failed_attempts)->toBe(1);
});

it('only gives up after several consecutive transient BGG failures', function () {
    $user = actingAsUser();
    $import = BggImport::factory()->for($user)->create(['bgg_username' => 'odei']);

    Http::fake(fn () => Http::response('', 500));

    $this->getJson("/api/bgg-imports/{$import->id}")->assertJsonPath('data.status', 'pending');
    $this->getJson("/api/bgg-imports/{$import->id}")->assertJsonPath('data.status', 'pending');
    $response = $this->getJson("/api/bgg-imports/{$import->id}");

    $response->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_message', "Couldn't reach BoardGameGeek.");
});

it('resets the transient failure count once BGG answers successfully again', function () {
    $user = actingAsUser();
    $import = BggImport::factory()->for($user)->create([
        'bgg_username' => 'odei',
        'failed_attempts' => 2,
    ]);

    fakeSuccessfulBggImport();

    $this->getJson("/api/bgg-imports/{$import->id}")->assertJsonPath('data.status', 'completed');

    expect($import->refresh()->failed_attempts)->toBe(0);
});
