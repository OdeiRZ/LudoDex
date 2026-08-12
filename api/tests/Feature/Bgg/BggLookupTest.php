<?php

use Illuminate\Support\Facades\Http;

function thingXmlForLookup(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <items>
        <item type="boardgame" id="13">
            <thumbnail>https://example.com/catan_t.jpg</thumbnail>
            <image>https://example.com/catan.jpg</image>
            <name type="primary" sortindex="1" value="Catan"/>
            <name type="alternate" sortindex="1" value="Colonos de Catán"/>
            <yearpublished value="1995"/>
            <minage value="10"/>
            <minplayers value="3"/>
            <maxplayers value="4"/>
            <minplaytime value="60"/>
            <maxplaytime value="120"/>
            <link type="boardgamemechanic" id="2027" value="Trading"/>
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
    </items>
    XML;
}

it('fails immediately when no BGG application token is configured', function () {
    actingAsUser();
    config(['bgg.application_token' => null]);

    Http::fake(fn () => Http::response('should not be called', 500));

    $this->getJson('/api/bgg-lookup/games/13')->assertUnprocessable();
    Http::assertNothingSent();
});

it('returns the BGG service error messages in the requested language', function () {
    actingAsUser();
    config(['bgg.application_token' => null]);

    Http::fake(fn () => Http::response('should not be called', 500));

    $this->withHeader('Accept-Language', 'en')
        ->getJson('/api/bgg-lookup/games/13')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'BGG_APPLICATION_TOKEN is not configured (BoardGameGeek requires a registered application token; see https://boardgamegeek.com/using_the_xml_api).');
});

it('looks up a game by its BGG id', function () {
    actingAsUser();
    Http::fake(fn () => Http::response(thingXmlForLookup()));

    $response = $this->getJson('/api/bgg-lookup/games/13');

    $response->assertOk()->assertJson(['data' => [
        'bgg_id' => 13,
        'name' => 'Catan',
        'image_url' => 'https://example.com/catan.jpg',
        'year_published' => 1995,
        'min_age' => '10+',
        'bgg_rank' => 343,
        'rating' => 7.13,
        'min_players' => 3,
        'max_players' => 4,
        'min_playtime_minutes' => 60,
        'max_playtime_minutes' => 120,
        'weight' => 2.32,
        'mechanics' => ['Trading'],
        'categories' => ['Negotiation'],
    ]]);
});

it('fails when the BGG id does not exist', function () {
    actingAsUser();
    Http::fake(fn () => Http::response('<?xml version="1.0"?><items></items>'));

    $this->getJson('/api/bgg-lookup/games/999999999')->assertUnprocessable();
});
