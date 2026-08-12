<?php

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const CSV_HEADER = 'objectname,objectid,itemtype,own,wishlist,privatecomment,avgweight,minplaytime,maxplaytime,yearpublished,rank,average,bggrecagerange,minplayers,maxplayers';

// Neutral values appended to every existing row below so they still match
// CSV_HEADER's column count - the dedicated tests further down exercise
// what each of these six fields actually parses into.
const CSV_EXTRA = ['30', '60', '2020', '100', '7.5', '10+'];

function csvUpload(string $header, array $rows): UploadedFile
{
    $lines = [$header, ...array_map(fn (array $row) => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent('collection.csv', implode("\n", $lines)."\n");
}

/**
 * The test client's plain post() looks like a browser form submission (no
 * Accept header), so Laravel's auth/validation error handling tries to
 * redirect (302, even to a "login" route this API-only app doesn't have)
 * instead of returning JSON - an explicit Accept header is what makes it
 * behave like the real SPA's axios requests do.
 */
function postCsv(?UploadedFile $file): TestResponse
{
    $data = $file === null ? [] : ['file' => $file];

    return test()->withHeader('Accept', 'application/json')->post('/api/bgg-imports/csv', $data);
}

it('rejects the request when no file is sent', function () {
    actingAsUser();

    postCsv(null)->assertUnprocessable();
});

it('rejects a file missing the columns a BGG export should have', function () {
    actingAsUser();

    $file = UploadedFile::fake()->createWithContent('collection.csv', "name,id\nCatan,13\n");

    test()->withHeader('Accept-Language', 'es')->withHeader('Accept', 'application/json')
        ->post('/api/bgg-imports/csv', ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Este archivo no parece ser una exportación de colección de BoardGameGeek (faltan columnas esperadas).');
});

it('imports an owned game, taking player counts from BGG\'s own columns and mode from the private comment', function () {
    $user = actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.7997', ...CSV_EXTRA, '1', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 1)
        ->assertJsonPath('data.skipped_expansions_count', 0)
        ->assertJsonPath('data.warnings', []);

    $game = Game::where('bgg_id', 191189)->first();
    expect($game)->not->toBeNull()
        ->and($game->name)->toBe("Aeon's End")
        ->and($game->is_cooperative)->toBeTrue()
        ->and($game->is_competitive)->toBeFalse()
        ->and($game->min_players)->toBe(1)
        ->and($game->max_players)->toBe(4)
        ->and($game->weight)->toBe(2.8);

    expect($user->games()->where('game_id', $game->id)->first()->status)->toBe('owned');
});

it('imports a wishlist game as wishlist, not owned', function () {
    $user = actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Ark Nova"', '342942', 'standalone', '0', '1', '"Competitivo - 1/4"', '3.7', ...CSV_EXTRA, '1', '4'],
    ]);

    postCsv($file)->assertOk();

    $game = Game::where('bgg_id', 342942)->first();
    expect($user->games()->where('game_id', $game->id)->first()->status)->toBe('wishlist');
});

it('recognizes the cooperative/competitive combo and the solo-only case', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Combo Game"', '1', 'standalone', '1', '0', '"Cooperativo/Competitivo - 1/4"', '0', ...CSV_EXTRA, '1', '4'],
        ['"Solo Game"', '2', 'standalone', '1', '0', '"Solitario"', '0', ...CSV_EXTRA, '1', '1'],
    ]);

    postCsv($file)->assertOk();

    $combo = Game::where('bgg_id', 1)->first();
    expect($combo->is_cooperative)->toBeTrue()->and($combo->is_competitive)->toBeTrue();

    $solo = Game::where('bgg_id', 2)->first();
    expect($solo->is_cooperative)->toBeFalse()
        ->and($solo->is_competitive)->toBeFalse()
        ->and($solo->min_players)->toBe(1)
        ->and($solo->max_players)->toBe(1);
});

it("takes min_players/max_players from BGG's own columns even when the private comment has no mode keyword", function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Mystery Game"', '4', 'standalone', '1', '0', '"no idea what this means"', '0', ...CSV_EXTRA, '2', '6'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 1)
        ->assertJsonPath('data.warnings', []);

    $game = Game::where('bgg_id', 4)->first();
    expect($game)->not->toBeNull()
        ->and($game->min_players)->toBe(2)
        ->and($game->max_players)->toBe(6)
        ->and($game->is_cooperative)->toBeFalse()
        ->and($game->is_competitive)->toBeFalse();
});

it('leaves max_players unset when BGG reports 0 (no known maximum), instead of storing a literal 0', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Open-Ended Game"', '9', 'standalone', '1', '0', '""', '0', ...CSV_EXTRA, '2', '0'],
    ]);

    postCsv($file)->assertOk();

    $game = Game::where('bgg_id', 9)->first();
    expect($game->min_players)->toBe(2)->and($game->max_players)->toBeNull();
});

it('skips expansions entirely when no BGG token is configured, since the CSV alone has no base game link', function () {
    $user = actingAsUser();
    config(['bgg.application_token' => null]);

    Http::fake(fn () => Http::response('should not be called', 500));

    $file = csvUpload(CSV_HEADER, [
        ['"Some Expansion"', '5', 'expansion', '1', '0', '"Competitivo - 2/4"', '0', ...CSV_EXTRA, '2', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 0)
        ->assertJsonPath('data.skipped_expansions_count', 1);

    expect(Game::where('bgg_id', 5)->exists())->toBeFalse();
    expect($user->games()->count())->toBe(0);
    Http::assertNothingSent();
});

it('imports and links an expansion to its base game when a BGG token is available', function () {
    $user = actingAsUser();

    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgameexpansion" id="9209">
            <name type="primary" sortindex="1" value="Catan: Seafarers"/>
            <link type="boardgameexpansion" id="13" value="Catan" inbound="true"/>
        </item>
    </items>
    XML));

    $file = csvUpload(CSV_HEADER, [
        ['"Catan"', '13', 'standalone', '1', '0', '""', '0', ...CSV_EXTRA, '3', '4'],
        ['"Catan: Seafarers"', '9209', 'expansion', '1', '0', '""', '0', ...CSV_EXTRA, '3', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 2)
        ->assertJsonPath('data.skipped_expansions_count', 0)
        ->assertJsonPath('data.warnings', []);

    $catan = Game::where('bgg_id', 13)->first();
    $seafarers = Game::where('bgg_id', 9209)->first();

    expect($seafarers->base_game_id)->toBe($catan->id);
    expect($user->games()->count())->toBe(2);

    // fetchGameDetails() only needs the expansion's own id - the base
    // game's data all comes straight from its own CSV row, same as before.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'id=9209') && ! str_contains($request->url(), '13'));
});

it('imports an expansion without linking it, and warns about it, when its base game is not in the same file', function () {
    actingAsUser();

    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgameexpansion" id="9209">
            <name type="primary" sortindex="1" value="Catan: Seafarers"/>
            <link type="boardgameexpansion" id="13" value="Catan" inbound="true"/>
        </item>
    </items>
    XML));

    $file = csvUpload(CSV_HEADER, [
        ['"Catan: Seafarers"', '9209', 'expansion', '1', '0', '""', '0', ...CSV_EXTRA, '3', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 1)
        ->assertJsonPath('data.skipped_expansions_count', 0);

    $seafarers = Game::where('bgg_id', 9209)->first();
    expect($seafarers)->not->toBeNull()
        ->and($seafarers->base_game_id)->toBeNull();

    expect($response->json('data.warnings'))->toHaveCount(1)
        ->and($response->json('data.warnings.0'))->toContain('Catan: Seafarers');
});

it('names the base game in the warning when it is already cached from an earlier BGG call', function () {
    actingAsUser();
    Cache::put('bgg:thing:13', ['name' => 'Catan'], now()->addDay());

    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgameexpansion" id="9209">
            <name type="primary" sortindex="1" value="Catan: Seafarers"/>
            <link type="boardgameexpansion" id="13" value="Catan" inbound="true"/>
        </item>
    </items>
    XML));

    $file = csvUpload(CSV_HEADER, [
        ['"Catan: Seafarers"', '9209', 'expansion', '1', '0', '""', '0', ...CSV_EXTRA, '3', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    expect($response->json('data.warnings'))->toHaveCount(1)
        ->and($response->json('data.warnings.0'))
        ->toContain('Catan: Seafarers')
        ->toContain('Catan"');
});

it('skips a row that is neither owned nor wishlisted', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Traded Away"', '6', 'standalone', '0', '0', '"Competitivo - 2/4"', '0', ...CSV_EXTRA, '2', '4'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 0)
        ->assertJsonPath('data.skipped_no_status_count', 1);
});

it('updates rather than duplicates when the same bgg id is imported twice', function () {
    $user = actingAsUser();

    $makeFile = fn () => csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8', ...CSV_EXTRA, '1', '4'],
    ]);

    postCsv($makeFile())->assertOk();
    postCsv($makeFile())->assertOk();

    expect(Game::where('bgg_id', 191189)->count())->toBe(1);
    expect($user->games()->count())->toBe(1);
});

it('does not wipe mechanics/categories already set on a game from a real BGG import', function () {
    actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 191189, 'name' => 'Aeon\'s End']);
    $game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Hand Management']));
    $game->categories()->attach(Category::factory()->create(['name' => 'Fantasy']));

    $file = csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8', ...CSV_EXTRA, '1', '4'],
    ]);

    postCsv($file)->assertOk();

    expect($game->refresh()->mechanics()->count())->toBe(1);
    expect($game->refresh()->categories()->count())->toBe(1);
});

it('rejects unauthenticated requests', function () {
    $file = csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8', ...CSV_EXTRA, '1', '4'],
    ]);

    postCsv($file)->assertUnauthorized();
});

it('imports playtime, year, rank, rating and the literal age range from a row', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"New Fields Game"', '7', 'standalone', '1', '0', '"Competitivo - 2/4"', '3.5', '45', '90', '2019', '120', '8.2', '4-12', '2', '4'],
    ]);

    postCsv($file)->assertOk();

    $game = Game::where('bgg_id', 7)->first();
    expect($game->min_playtime_minutes)->toBe(45)
        ->and($game->max_playtime_minutes)->toBe(90)
        ->and($game->year_published)->toBe(2019)
        ->and($game->bgg_rank)->toBe(120)
        ->and($game->rating)->toBe(8.2)
        ->and($game->min_age)->toBe('4-12');
});

it('stores a null bgg_rank when the csv rank is 0, since that means unranked, not unknown', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Unranked Game"', '8', 'standalone', '1', '0', '"Competitivo - 2/4"', '2.0', '30', '60', '2021', '0', '6.5', '8+', '2', '4'],
    ]);

    postCsv($file)->assertOk();

    $game = Game::where('bgg_id', 8)->first();
    expect($game->bgg_rank)->toBeNull();
});
