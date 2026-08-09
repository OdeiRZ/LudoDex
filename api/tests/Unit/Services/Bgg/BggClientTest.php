<?php

use App\Services\Bgg\BggClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

// fetchCollection() always makes two requests (boardgame, then
// boardgameexpansion subtypes) and concatenates their items - so a plain
// Http::fake(fn () => ...) with a single item would double it up. This fakes
// the expansion call as empty, keeping the item count meaningful.
function fakeCollectionOf(string $itemXml): void
{
    Http::fake(function (ClientRequest $request) use ($itemXml) {
        if (str_contains($request->url(), 'subtype=boardgameexpansion')) {
            return Http::response('<?xml version="1.0"?><items totalitems="0"></items>');
        }

        return Http::response($itemXml);
    });
}

// These exercise BggClient's own XML-parsing edge cases directly (no
// controller, no database) - the existing Feature tests under
// tests/Feature/Bgg cover the request/response/DB round trip, but every one
// of their fixtures happens to include a full <stats> block and well-formed
// XML, so the defensive paths below (missing stats, garbage bodies, "N/A"
// avatars) were never actually exercised anywhere.

it('treats a collection item with no stats block at all as having no player/duration data', function () {
    fakeCollectionOf(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items totalitems="1">
        <item objecttype="thing" objectid="13" subtype="boardgame" collid="1">
            <name sortindex="1">Catan</name>
            <status own="1" wishlist="0"/>
        </item>
    </items>
    XML);

    $result = (new BggClient)->fetchCollection('odei');

    expect($result['status'])->toBe('ready')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['min_players'])->toBeNull()
        ->and($result['items'][0]['max_players'])->toBeNull()
        ->and($result['items'][0]['min_playtime_minutes'])->toBeNull()
        ->and($result['items'][0]['max_playtime_minutes'])->toBeNull();
});

it('treats a stat attribute present but empty as no value, not zero', function () {
    fakeCollectionOf(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items totalitems="1">
        <item objecttype="thing" objectid="13" subtype="boardgame" collid="1">
            <name sortindex="1">Catan</name>
            <status own="1" wishlist="0"/>
            <stats minplayers="" maxplayers="4"/>
        </item>
    </items>
    XML);

    $result = (new BggClient)->fetchCollection('odei');

    expect($result['items'][0]['min_players'])->toBeNull()
        ->and($result['items'][0]['max_players'])->toBe(4);
});

it('reports an error instead of crashing when BGG answers with a non-XML body', function () {
    // Not just any unexpected content: has to actually fail
    // simplexml_load_string() - well-formed-but-unrelated XML like
    // "<html>...</html>" parses fine (root element just has no <item>
    // children), so this needs genuinely malformed content.
    Http::fake(fn () => Http::response('not xml at all'));

    $result = (new BggClient)->fetchCollection('odei');

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toBe(__('bgg.unexpected_response'));
});

it('reports the game as not found instead of crashing on a non-XML /thing response', function () {
    Http::fake(fn () => Http::response('not xml at all'));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toBe(__('bgg.game_not_found'));
});

it('treats a literal "N/A" avatar the same as no avatar at all', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0"?><user id="1" name="odei"><avatarlink value="N/A"/></user>'
    ));

    expect((new BggClient)->fetchUserAvatar('odei'))->toBeNull();
});

it('returns null for a user response with no id (unknown username)', function () {
    Http::fake(fn () => Http::response('<?xml version="1.0"?><user></user>'));

    expect((new BggClient)->fetchUserAvatar('does-not-exist'))->toBeNull();
});

it('returns null instead of crashing when the avatar lookup gets a non-XML body', function () {
    Http::fake(fn () => Http::response('not xml at all'));

    expect((new BggClient)->fetchUserAvatar('odei'))->toBeNull();
});
