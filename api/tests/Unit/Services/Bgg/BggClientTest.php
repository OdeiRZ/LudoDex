<?php

use App\Services\Bgg\BggClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// fetchPlays() paces itself between pages, and retries a 429 with backoff -
// both via Sleep::usleep()/Sleep::for(), so every test in this file fakes it
// rather than actually waiting real seconds per multi-page/retry scenario.
beforeEach(function () {
    Sleep::fake();
});

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

function playXml(int $id, int $bggId, string $name, string $date, ?string $quantity = null, ?string $length = null): string
{
    $quantityAttr = $quantity !== null ? " quantity=\"{$quantity}\"" : '';
    $lengthAttr = $length !== null ? " length=\"{$length}\"" : '';

    return <<<XML
    <play id="{$id}" date="{$date}"{$quantityAttr}{$lengthAttr}>
        <item name="{$name}" objectid="{$bggId}" objecttype="thing"/>
    </play>
    XML;
}

it('fetches a single page of plays and stops once fewer than 100 are returned', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="1" page="1">'
        .playXml(1001, 13, 'Catan', '2026-01-01', quantity: '2', length: '60')
        .'</plays>'
    ));

    $result = (new BggClient)->fetchPlays('odei');

    Http::assertSentCount(1);
    expect($result['status'])->toBe('ready')
        ->and($result['plays'])->toHaveCount(1)
        ->and($result['plays'][0])->toBe([
            'bgg_play_id' => 1001,
            'bgg_id' => 13,
            'name' => 'Catan',
            'played_at' => '2026-01-01',
            'quantity' => 2,
            'duration_minutes' => 60,
        ]);
});

it('omits mindate from the request when none is given', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="0" page="1"></plays>'
    ));

    (new BggClient)->fetchPlays('odei');

    Http::assertSent(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return ! array_key_exists('mindate', $query);
    });
});

it('passes mindate through to BGG on every page requested', function () {
    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];

        expect($query['mindate'] ?? null)->toBe('2025-12-25');

        $count = $page === 1 ? 100 : 1;
        $plays = '';

        for ($i = 0; $i < $count; $i++) {
            $plays .= playXml(($page * 1000) + $i, 13, 'Catan', '2026-01-01');
        }

        return Http::response('<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="101" page="'.$page.'">'.$plays.'</plays>');
    });

    (new BggClient)->fetchPlays('odei', '2025-12-25');

    Http::assertSentCount(2);
});

it('defaults quantity to 1 and duration to null when BGG omits them', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="1" page="1">'
        .playXml(1002, 13, 'Catan', '2026-01-02')
        .'</plays>'
    ));

    $result = (new BggClient)->fetchPlays('odei');

    expect($result['plays'][0]['quantity'])->toBe(1)
        ->and($result['plays'][0]['duration_minutes'])->toBeNull();
});

it('keeps requesting pages until one comes back with fewer than 100 plays', function () {
    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];

        $count = $page === 1 ? 100 : 1;
        $plays = '';

        for ($i = 0; $i < $count; $i++) {
            $plays .= playXml(($page * 1000) + $i, 13, 'Catan', '2026-01-01');
        }

        return Http::response('<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="101" page="'.$page.'">'.$plays.'</plays>');
    });

    $result = (new BggClient)->fetchPlays('odei');

    Http::assertSentCount(2);
    expect($result['plays'])->toHaveCount(101);
});

it('skips a play with no nested item instead of crashing', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="1" page="1"><play id="1" date="2026-01-01"/></plays>'
    ));

    $result = (new BggClient)->fetchPlays('odei');

    expect($result['status'])->toBe('ready')->and($result['plays'])->toBe([]);
});

it('reports an error for /plays without crashing when the username does not exist', function () {
    Http::fake(fn () => Http::response(
        '<?xml version="1.0" encoding="utf-8"?><errors><error><message>Invalid username specified</message></error></errors>'
    ));

    $result = (new BggClient)->fetchPlays('nobody');

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toBe('Invalid username specified')
        ->and($result['transient'])->toBeFalse();
});

it('retries a 429 from BGG with backoff before succeeding', function () {
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;

        if ($attempt < 3) {
            return Http::response('', 429);
        }

        return Http::response(
            '<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="1" page="1">'
            .playXml(1, 13, 'Catan', '2026-01-01')
            .'</plays>'
        );
    });

    $result = (new BggClient)->fetchPlays('odei');

    Http::assertSentCount(3);
    expect($result['status'])->toBe('ready')->and($result['plays'])->toHaveCount(1);
    Sleep::assertSequence([
        Sleep::for(3)->seconds(),
        Sleep::for(6)->seconds(),
    ]);
});

it('gives up as a transient error after a 429 from BGG never clears', function () {
    Http::fake(fn () => Http::response('', 429));

    $result = (new BggClient)->fetchPlays('odei');

    Http::assertSentCount(6); // one immediate attempt + 5 backed-off retries
    expect($result['status'])->toBe('error')
        ->and($result['transient'])->toBeTrue();
});

it('doubles the pacing delay for the rest of the run once any page gets rate-limited', function () {
    $callsByPage = [];
    Http::fake(function (ClientRequest $request) use (&$callsByPage) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];
        $callsByPage[$page] = ($callsByPage[$page] ?? 0) + 1;

        // Page 2's first attempt is rate-limited; every other request
        // (including page 2's own retry) succeeds right away.
        if ($page === 2 && $callsByPage[$page] === 1) {
            return Http::response('', 429);
        }

        // Page 3 is the one with fewer than 100, stopping pagination there.
        $count = $page < 3 ? 100 : 1;
        $plays = '';

        for ($i = 0; $i < $count; $i++) {
            $plays .= playXml(($page * 1000) + $i, 13, 'Catan', '2026-01-01');
        }

        return Http::response('<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="201" page="'.$page.'">'.$plays.'</plays>');
    });

    (new BggClient)->fetchPlays('odei');

    // Page 1 -> page 2: base 1s pace. Page 2's own retry: the backoff
    // schedule's own first delay (3s). Page 2 -> page 3: doubled to 2s,
    // since page 2 needed a retry at all.
    Sleep::assertSequence([
        Sleep::usleep(1_000_000),
        Sleep::for(3)->seconds(),
        Sleep::usleep(2_000_000),
    ]);
});

it('pauses between pages to avoid triggering BGG\'s own rate limit in the first place', function () {
    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];

        $count = $page === 1 ? 100 : 1;
        $plays = '';

        for ($i = 0; $i < $count; $i++) {
            $plays .= playXml(($page * 1000) + $i, 13, 'Catan', '2026-01-01');
        }

        return Http::response('<?xml version="1.0" encoding="utf-8"?><plays username="odei" total="101" page="'.$page.'">'.$plays.'</plays>');
    });

    (new BggClient)->fetchPlays('odei');

    Http::assertSentCount(2);
    // One pause before the second page - none before the very first request.
    Sleep::assertSequence([
        Sleep::usleep(1_000_000),
    ]);
});

it('does not call BGG for plays when no application token is configured', function () {
    config(['bgg.application_token' => null]);
    Http::fake(fn () => Http::response('should not be called', 500));

    $result = (new BggClient)->fetchPlays('odei');

    expect($result['status'])->toBe('error');
    Http::assertNothingSent();
});
