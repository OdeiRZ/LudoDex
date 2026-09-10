<?php

use App\Models\Friendship;
use App\Models\Game;
use App\Models\User;
use App\Models\UserGame;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/friends/1/games')->assertUnauthorized();
});

it('classifies games as shared, mine_only or theirs_only between accepted friends', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $shared = Game::factory()->create(['name' => 'Catan']);
    $mine = Game::factory()->create(['name' => 'Wingspan']);
    $theirs = Game::factory()->create(['name' => 'Azul']);

    UserGame::factory()->for($me)->for($shared)->create();
    UserGame::factory()->for($friend)->for($shared)->create();
    UserGame::factory()->for($me)->for($mine)->create();
    UserGame::factory()->for($friend)->for($theirs)->create();

    $response = $this->getJson("/api/friends/{$friend->id}/games")->assertOk();

    expect(collect($response->json('data.shared'))->pluck('name')->all())->toBe(['Catan']);
    expect(collect($response->json('data.mine_only'))->pluck('name')->all())->toBe(['Wingspan']);
    expect(collect($response->json('data.theirs_only'))->pluck('name')->all())->toBe(['Azul']);
});

it('only compares owned games, ignoring wishlist entries on both sides', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $myWishlist = Game::factory()->create(['name' => 'On My Wishlist']);
    $theirWishlist = Game::factory()->create(['name' => 'On Their Wishlist']);
    UserGame::factory()->for($me)->for($myWishlist)->create(['status' => 'wishlist']);
    UserGame::factory()->for($friend)->for($theirWishlist)->create(['status' => 'wishlist']);

    $response = $this->getJson("/api/friends/{$friend->id}/games")->assertOk();

    expect($response->json('data.shared'))->toBe([]);
    expect($response->json('data.mine_only'))->toBe([]);
    expect($response->json('data.theirs_only'))->toBe([]);
});

it('treats the same catalog game shared by two accounts as shared, not duplicated', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $game = Game::factory()->create(['name' => 'Catan']);
    UserGame::factory()->for($me)->for($game)->create();
    UserGame::factory()->for($friend)->for($game)->create();

    $response = $this->getJson("/api/friends/{$friend->id}/games")->assertOk();

    $response->assertJsonCount(1, 'data.shared');
});

it('returns the friend\'s public info without leaking their email', function () {
    $me = actingAsUser();
    $friend = User::factory()->create(['name' => 'Friend One', 'email' => 'friend@example.com']);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/games")->assertOk()
        ->assertJsonPath('data.friend.name', 'Friend One')
        ->assertJsonMissingPath('data.friend.email');
});

it('responds identically (status and body) for a non-existent friend id, a pending friend, and someone else\'s accepted friend', function () {
    // Forces the production shape of the 404 response (no debug trace) -
    // with APP_DEBUG on, each abort(404) response would carry a stack
    // trace pointing at wherever it was called from, differing between
    // these three calls purely because they're separate lines in this
    // test, not because the responses actually differ in production.
    config(['app.debug' => false]);
    $me = actingAsUser();
    $pending = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $pending->id]);
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);

    $forMissing = $this->getJson('/api/friends/999999/games');
    $forPending = $this->getJson("/api/friends/{$pending->id}/games");
    $forSomeoneElsesFriend = $this->getJson("/api/friends/{$b->id}/games");

    $forMissing->assertNotFound();
    $forPending->assertNotFound();
    $forSomeoneElsesFriend->assertNotFound();
    expect($forMissing->json())->toBe($forPending->json());
    expect($forMissing->json())->toBe($forSomeoneElsesFriend->json());
});

it('rejects (403) with a specific message when the friend has turned off activity sharing', function () {
    $me = actingAsUser();
    $friend = User::factory()->create(['share_activity' => false]);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/games")
        ->assertForbidden()
        ->assertJsonPath('message', __('friends.activity_not_shared'));
});

it('allows the collection comparison by default, since share_activity defaults to true', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/games")->assertOk();
});

it('returns 200 with the expected shape for an accepted friend', function () {
    $me = actingAsUser();
    $friend = User::factory()->create(['name' => 'Friend One']);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/games")
        ->assertOk()
        ->assertJsonStructure(['data' => ['friend', 'shared', 'mine_only', 'theirs_only']]);
});
