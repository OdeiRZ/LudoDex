<?php

use App\Models\Game;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\UserGame;

function sharedCollection(array $userAttributes = []): array
{
    $owner = User::factory()->create(['name' => 'Ana Dueña', ...$userAttributes]);
    $link = ShareLink::factory()->for($owner)->create();

    return [$owner, $link];
}

it('serves the owned games without any session, in alphabetical order, with the owner name', function () {
    [$owner, $link] = sharedCollection();
    UserGame::factory()->for($owner)->for(Game::factory()->create(['name' => 'Wingspan']))->create();
    UserGame::factory()->for($owner)->for(Game::factory()->create(['name' => 'Azul']))->create();

    $response = $this->getJson("/api/public/collections/{$link->token}")->assertOk();

    expect($response->json('data.owner_name'))->toBe('Ana Dueña');
    expect(collect($response->json('data.games'))->pluck('name')->all())->toBe(['Azul', 'Wingspan']);
    expect($response->json('data.expires_at'))->not->toBeNull();
});

it('does NOT show the wishlist, only what is owned', function () {
    [$owner, $link] = sharedCollection();
    UserGame::factory()->for($owner)->for(Game::factory()->create(['name' => 'Lo tengo']))->create(['status' => 'owned']);
    UserGame::factory()->for($owner)->for(Game::factory()->create(['name' => 'Lo quiero']))->create(['status' => 'wishlist']);

    $response = $this->getJson("/api/public/collections/{$link->token}")->assertOk();

    expect(collect($response->json('data.games'))->pluck('name')->all())->toBe(['Lo tengo']);
});

it('never shows another user\'s games', function () {
    [$owner, $link] = sharedCollection();
    $stranger = User::factory()->create();
    UserGame::factory()->for($owner)->for(Game::factory()->create(['name' => 'Mío']))->create();
    UserGame::factory()->for($stranger)->for(Game::factory()->create(['name' => 'Ajeno']))->create();

    $response = $this->getJson("/api/public/collections/{$link->token}")->assertOk();

    expect(collect($response->json('data.games'))->pluck('name')->all())->toBe(['Mío']);
});

it('leaks nothing about the user beyond the name: no email, id, plays or settings', function () {
    [$owner, $link] = sharedCollection(['email' => 'secreto@example.test']);
    UserGame::factory()->for($owner)->for(Game::factory()->create())->create();

    $body = $this->getJson("/api/public/collections/{$link->token}")->assertOk()->getContent();

    expect($body)->not->toContain('secreto@example.test');
    expect(array_keys(json_decode($body, true)['data']))->toBe(['owner_name', 'expires_at', 'games']);
    foreach (['"email"', '"user_id"', '"share_activity"', '"discoverable"', '"token"'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('answers 404 identically to a malformed, unknown, expired or revoked token', function () {
    $expired = ShareLink::factory()->expired()->create();

    $this->getJson('/api/public/collections/corto')->assertNotFound();
    $this->getJson('/api/public/collections/'.str_repeat('a', 43))->assertNotFound();
    $this->getJson("/api/public/collections/{$expired->token}")->assertNotFound();
    $this->getJson('/api/public/collections/'.str_repeat('!', 43))->assertNotFound();
});

it('serves a link that never expires', function () {
    $link = ShareLink::factory()->neverExpires()->create();

    $this->getJson("/api/public/collections/{$link->token}")->assertOk()->assertJsonPath('data.expires_at', null);
});

it('counts the visits and remembers the last one', function () {
    [, $link] = sharedCollection();

    $this->getJson("/api/public/collections/{$link->token}")->assertOk();
    $this->getJson("/api/public/collections/{$link->token}")->assertOk();

    expect($link->fresh()->views_count)->toBe(2);
    expect($link->fresh()->last_viewed_at)->not->toBeNull();
});

it('asks search engines not to index it and not to cache it', function () {
    [, $link] = sharedCollection();

    $response = $this->getJson("/api/public/collections/{$link->token}")->assertOk();

    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('limits how often one client can ask', function () {
    [, $link] = sharedCollection();

    foreach (range(1, 60) as $_) {
        $this->getJson("/api/public/collections/{$link->token}")->assertOk();
    }

    $this->getJson("/api/public/collections/{$link->token}")->assertStatus(429);
});

it('removes the link when its owner is deleted', function () {
    [$owner, $link] = sharedCollection();

    $owner->delete();

    $this->getJson("/api/public/collections/{$link->token}")->assertNotFound();
});
