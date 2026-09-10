<?php

use App\Models\Friendship;
use App\Models\Game;
use App\Models\Play;
use App\Models\User;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/friends/1/plays/stats')->assertUnauthorized();
});

it('aggregates totals scoped to the friend\'s plays only', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($friend)->for($game)->create(['quantity' => 3]);
    Play::factory()->for($me)->for($game)->create(['quantity' => 10]);

    $response = $this->getJson("/api/friends/{$friend->id}/plays/stats")->assertOk();

    $response->assertJsonPath('data.total_plays', 3)->assertJsonPath('data.distinct_games', 1);
    expect($response->json('friend.id'))->toBe($friend->id);
});

it('rejects (403) with a specific message when the friend has turned off activity sharing', function () {
    $me = actingAsUser();
    $friend = User::factory()->create(['share_activity' => false]);
    Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->getJson("/api/friends/{$friend->id}/plays/stats")
        ->assertForbidden()
        ->assertJsonPath('message', __('friends.activity_not_shared'));
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

    $forMissing = $this->getJson('/api/friends/999999/plays/stats');
    $forPending = $this->getJson("/api/friends/{$pending->id}/plays/stats");
    $forSomeoneElsesFriend = $this->getJson("/api/friends/{$b->id}/plays/stats");

    $forMissing->assertNotFound();
    $forPending->assertNotFound();
    $forSomeoneElsesFriend->assertNotFound();
    expect($forMissing->json())->toBe($forPending->json());
    expect($forMissing->json())->toBe($forSomeoneElsesFriend->json());
});
