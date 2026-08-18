<?php

use App\Services\Bgg\BggClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
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

it('extracts year published, recommended age, board game rank and rating from a full /thing response', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="30549">
            <name type="primary" sortindex="1" value="Pandemic"/>
            <yearpublished value="2008"/>
            <minage value="8"/>
            <statistics page="1">
                <ratings>
                    <average value="7.51133"/>
                    <bayesaverage value="7.40169"/>
                    <ranks>
                        <rank type="family" id="5497" name="strategygames" friendlyname="Strategy Game Rank" value="179" bayesaverage="7.3272"/>
                        <rank type="subtype" id="1" name="boardgame" friendlyname="Board Game Rank" value="174" bayesaverage="7.40169"/>
                    </ranks>
                    <averageweight value="2.35"/>
                </ratings>
            </statistics>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(30549);

    expect($result['status'])->toBe('ready')
        ->and($result['game']['year_published'])->toBe(2008)
        ->and($result['game']['min_age'])->toBe('8+')
        ->and($result['game']['bgg_rank'])->toBe(174)
        ->and($result['game']['rating'])->toBe(7.51)
        ->and($result['game']['weight'])->toBe(2.35);
});

it('rounds rating/weight to two decimals, since BGG reports far more precision than the edit form\'s step="0.01" inputs accept', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
            <statistics page="1">
                <ratings>
                    <average value="7.09045"/>
                    <averageweight value="2.2809"/>
                </ratings>
            </statistics>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['rating'])->toBe(7.09)
        ->and($result['game']['weight'])->toBe(2.28);
});

it('treats a reported rating/weight of 0 as no data yet, not a real zero', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Obscure Game"/>
            <statistics page="1">
                <ratings>
                    <average value="0"/>
                    <averageweight value="0"/>
                </ratings>
            </statistics>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['rating'])->toBeNull()
        ->and($result['game']['weight'])->toBeNull();
});

it('treats a reported year/player-count/playtime of 0 as no data, not a real zero', function () {
    // Reproduces a real case: BGG's /thing response for "Poker Dice"
    // reports yearpublished value="0" instead of omitting the field, and
    // it sorted first in the collection's "oldest first" year sort as a
    // result - year 0 sorting before every real year, exactly the games-
    // with-no-year-data case that sort is supposed to sink to the bottom.
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Poker Dice"/>
            <yearpublished value="0"/>
            <minplayers value="0"/>
            <maxplayers value="0"/>
            <minplaytime value="0"/>
            <maxplaytime value="0"/>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['year_published'])->toBeNull()
        ->and($result['game']['min_players'])->toBeNull()
        ->and($result['game']['max_players'])->toBeNull()
        ->and($result['game']['min_playtime_minutes'])->toBeNull()
        ->and($result['game']['max_playtime_minutes'])->toBeNull();
});

it('treats a "Not Ranked" board game rank as no rank, not a cast-to-zero crash', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Obscure Game"/>
            <statistics page="1">
                <ratings>
                    <average value="0"/>
                    <ranks>
                        <rank type="subtype" id="1" name="boardgame" friendlyname="Board Game Rank" value="Not Ranked"/>
                    </ranks>
                </ratings>
            </statistics>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['bgg_rank'])->toBeNull();
});

it('decodes a doubly HTML-entity-encoded description into plain text', function () {
    // Real shape of BGG's own description field: the text itself is
    // entity-encoded (e.g. a literal "&amp;quot;" for a quote), on top of
    // XML's own encoding of that same ampersand as "&amp;amp;" - so the
    // raw XML body below has to double-escape to reproduce what BGG
    // actually sends. A single html_entity_decode() would still leave a
    // literal "&quot;" in the result; this needs two passes.
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
            <description>Trade, build &amp;amp; settle in this &amp;quot;classic&amp;quot; game.</description>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['description'])->toBe('Trade, build & settle in this "classic" game.');
});

it('treats a missing or blank description as null', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
            <description>   </description>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['description'])->toBeNull();
});

it('collapses runs of 3+ blank lines in a description down to one', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
            <description>First paragraph.&amp;#10;&amp;#10;&amp;#10;&amp;#10;Second paragraph.</description>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['description'])->toBe("First paragraph.\n\nSecond paragraph.");
});

it('treats missing yearpublished/minage/statistics on /thing as null instead of crashing', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
        </item>
    </items>
    XML));

    $result = (new BggClient)->fetchGameByBggId(13);

    expect($result['game']['year_published'])->toBeNull()
        ->and($result['game']['min_age'])->toBeNull()
        ->and($result['game']['bgg_rank'])->toBeNull()
        ->and($result['game']['rating'])->toBeNull();
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

// BGG's XML API terms of use ask that /thing results be cached rather than
// re-requested - a game's mechanics/rank/rating barely change day to day,
// and the same popular games keep coming up across different imports.

it('caches a /thing lookup so a second fetchGameByBggId for the same id does not call BGG again', function () {
    Http::fake(fn () => Http::response(<<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <name type="primary" sortindex="1" value="Catan"/>
        </item>
    </items>
    XML));

    $client = new BggClient;

    $first = $client->fetchGameByBggId(13);
    $second = $client->fetchGameByBggId(13);

    Http::assertSentCount(1);
    expect($first['game']['name'])->toBe('Catan')
        ->and($second['game']['name'])->toBe('Catan');
});

it('returns cached game names without making a BGG call', function () {
    Cache::put('bgg:thing:v2:13', ['name' => 'Catan'], now()->addDay());
    Http::fake(fn () => Http::response('should not be called', 500));

    expect((new BggClient)->getCachedGameNames([13]))->toBe([13 => 'Catan']);
    Http::assertNothingSent();
});

it('omits ids that are not cached, without making a BGG call either', function () {
    Cache::put('bgg:thing:v2:13', ['name' => 'Catan'], now()->addDay());
    Http::fake(fn () => Http::response('should not be called', 500));

    expect((new BggClient)->getCachedGameNames([13, 999]))->toBe([13 => 'Catan']);
    Http::assertNothingSent();
});

it('returns an empty array for an empty id list, without making a BGG call', function () {
    Http::fake(fn () => Http::response('should not be called', 500));

    expect((new BggClient)->getCachedGameNames([]))->toBe([]);
    Http::assertNothingSent();
});

it('only requests the ids not already cached in a mixed batch, keeping the cached one in the result', function () {
    // A single fake that echoes back whatever ids were actually requested -
    // needed because Http::fake() calls don't override each other (the
    // first unconditional stub keeps matching later requests too), so a
    // response that depends on the request is the only way to tell the two
    // fetchGameDetails() calls below apart.
    Http::fake(function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $items = collect(explode(',', $query['id']))
            ->map(fn (string $id) => "<item type=\"boardgame\" id=\"{$id}\"><name type=\"primary\" sortindex=\"1\" value=\"Game {$id}\"/></item>")
            ->implode('');

        return Http::response('<?xml version="1.0" encoding="utf-8"?><items>'.$items.'</items>');
    });

    $client = new BggClient;
    $client->fetchGameDetails([13]);
    $result = $client->fetchGameDetails([13, 9209]);

    Http::assertSentCount(2);
    $secondRequestUrl = Http::recorded()->last()[0]->url();

    expect($secondRequestUrl)->toContain('id=9209')
        ->and($secondRequestUrl)->not->toContain('13')
        ->and($result[13]['name'])->toBe('Game 13')
        ->and($result[9209]['name'])->toBe('Game 9209');
});
