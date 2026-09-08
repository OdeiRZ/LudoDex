<?php

use App\Models\Friendship;
use App\Models\Game;
use App\Models\Play;
use App\Models\User;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/friends/1/plays')->assertUnauthorized();
});

it('lists only the friend\'s plays, most recent first, paginated at 20', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($friend)->for($game)->create(['played_at' => '2026-01-01']);
    Play::factory()->for($friend)->for($game)->create(['played_at' => '2026-02-01']);

    $response = $this->getJson("/api/friends/{$friend->id}/plays")->assertOk();

    $response->assertJsonCount(2, 'data')->assertJsonPath('data.0.played_at', '2026-02-01');
    expect($response->json('friend.id'))->toBe($friend->id);
});

it('never includes the viewer\'s own plays even for the same game', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($friend)->for($game)->create();
    Play::factory()->for($me)->for($game)->create();

    $this->getJson("/api/friends/{$friend->id}/plays")->assertOk()->assertJsonCount(1, 'data');
});

it('filters by the played game\'s own name via ?search=', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);
    $catan = Game::factory()->create(['name' => 'Catan', 'bgg_id' => 13]);
    $sevenWonders = Game::factory()->create(['name' => '7 Wonders', 'bgg_id' => 68448]);
    Play::factory()->for($friend)->for($catan)->create();
    Play::factory()->for($friend)->for($sevenWonders)->create();

    $response = $this->getJson("/api/friends/{$friend->id}/plays?search=catan")->assertOk();

    $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.game.name', 'Catan');
});

it('paginates at 20 per page', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);
    $game = Game::factory()->create(['bgg_id' => 13]);
    Play::factory()->for($friend)->for($game)->count(25)->create();

    $this->getJson("/api/friends/{$friend->id}/plays")->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25);
});

it('rejects (403) with a specific message when the friend has turned off plays sharing', function () {
    $me = actingAsUser();
    $friend = User::factory()->create(['share_plays' => false]);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/plays")
        ->assertForbidden()
        ->assertJsonPath('message', __('friends.plays_not_shared'));
});

it('allows listing plays by default, since share_plays defaults to true', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/plays")->assertOk();
});

it('responds identically (status and body) for a non-existent friend id, a pending friend, and someone else\'s accepted friend', function () {
    // See the same-named test in FriendCollectionTest.php for why this is
    // needed: with APP_DEBUG on, each abort(404) carries its own call-site
    // trace, which would otherwise differ between these three calls for a
    // reason that has nothing to do with the actual production response.
    config(['app.debug' => false]);
    $me = actingAsUser();
    $pending = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $me->id, 'recipient_id' => $pending->id]);
    $a = User::factory()->create();
    $b = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $a->id, 'recipient_id' => $b->id]);

    $forMissing = $this->getJson('/api/friends/999999/plays');
    $forPending = $this->getJson("/api/friends/{$pending->id}/plays");
    $forSomeoneElsesFriend = $this->getJson("/api/friends/{$b->id}/plays");

    $forMissing->assertNotFound();
    $forPending->assertNotFound();
    $forSomeoneElsesFriend->assertNotFound();
    expect($forMissing->json())->toBe($forPending->json());
    expect($forMissing->json())->toBe($forSomeoneElsesFriend->json());
});
