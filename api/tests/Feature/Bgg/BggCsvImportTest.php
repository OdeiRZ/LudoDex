<?php

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

const CSV_HEADER = 'objectname,objectid,itemtype,own,wishlist,privatecomment,avgweight';

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

it('imports an owned game, parsing mode and players from the private comment', function () {
    $user = actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.7997'],
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
        ['"Ark Nova"', '342942', 'standalone', '0', '1', '"Competitivo - 1/4"', '3.7'],
    ]);

    postCsv($file)->assertOk();

    $game = Game::where('bgg_id', 342942)->first();
    expect($user->games()->where('game_id', $game->id)->first()->status)->toBe('wishlist');
});

it('recognizes the cooperative/competitive combo and the solo-only case', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Combo Game"', '1', 'standalone', '1', '0', '"Cooperativo/Competitivo - 1/4"', '0'],
        ['"Solo Game"', '2', 'standalone', '1', '0', '"Solitario"', '0'],
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

it('treats a literal X maximum as no upper limit instead of a parse failure', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Poker Dice"', '3', 'standalone', '1', '0', '"Competitivo - 2/X"', '1'],
    ]);

    postCsv($file)
        ->assertOk()
        ->assertJsonPath('data.warnings', []);

    $game = Game::where('bgg_id', 3)->first();
    expect($game->min_players)->toBe(2)->and($game->max_players)->toBeNull();
});

it('imports the game anyway, with a warning, when the private comment format is unrecognized', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Mystery Game"', '4', 'standalone', '1', '0', '"no idea what this means"', '0'],
    ]);

    $response = test()->withHeader('Accept-Language', 'en')->withHeader('Accept', 'application/json')
        ->post('/api/bgg-imports/csv', ['file' => $file])
        ->assertOk();

    $response->assertJsonPath('data.imported_count', 1)
        ->assertJsonPath('data.warnings.0', "Mystery Game: couldn't recognize the mode/players in the private comment.");

    expect(Game::where('bgg_id', 4)->exists())->toBeTrue();
});

it('skips expansions entirely, since this export has no expansion -> base game link', function () {
    $user = actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Some Expansion"', '5', 'expansion', '1', '0', '"Competitivo - 2/4"', '0'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 0)
        ->assertJsonPath('data.skipped_expansions_count', 1);

    expect(Game::where('bgg_id', 5)->exists())->toBeFalse();
    expect($user->games()->count())->toBe(0);
});

it('skips a row that is neither owned nor wishlisted', function () {
    actingAsUser();

    $file = csvUpload(CSV_HEADER, [
        ['"Traded Away"', '6', 'standalone', '0', '0', '"Competitivo - 2/4"', '0'],
    ]);

    $response = postCsv($file)->assertOk();

    $response->assertJsonPath('data.imported_count', 0)
        ->assertJsonPath('data.skipped_no_status_count', 1);
});

it('updates rather than duplicates when the same bgg id is imported twice', function () {
    $user = actingAsUser();

    $makeFile = fn () => csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8'],
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
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8'],
    ]);

    postCsv($file)->assertOk();

    expect($game->refresh()->mechanics()->count())->toBe(1);
    expect($game->refresh()->categories()->count())->toBe(1);
});

it('rejects unauthenticated requests', function () {
    $file = csvUpload(CSV_HEADER, [
        ["\"Aeon's End\"", '191189', 'standalone', '1', '0', '"Cooperativo - 1/4"', '2.8'],
    ]);

    postCsv($file)->assertUnauthorized();
});
