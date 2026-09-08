<?php

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;

it('rejects every block endpoint from a guest', function () {
    $this->getJson('/api/friends/blocks')->assertUnauthorized();
    $this->postJson('/api/friends/blocks', ['user_id' => 1])->assertUnauthorized();
    $this->deleteJson('/api/friends/blocks/1')->assertUnauthorized();
});

it('blocks a user', function () {
    $me = actingAsUser();
    $target = User::factory()->create();

    $this->postJson('/api/friends/blocks', ['user_id' => $target->id])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id']]);

    $this->assertDatabaseHas('user_blocks', ['blocker_id' => $me->id, 'blocked_id' => $target->id]);
});

it('rejects blocking yourself', function () {
    $me = actingAsUser();

    $this->postJson('/api/friends/blocks', ['user_id' => $me->id])->assertUnprocessable();
});

it('is idempotent - blocking someone already blocked is not an error', function () {
    $me = actingAsUser();
    $target = User::factory()->create();
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $target->id]);

    $this->postJson('/api/friends/blocks', ['user_id' => $target->id])->assertCreated();

    expect(UserBlock::where('blocker_id', $me->id)->where('blocked_id', $target->id)->count())->toBe(1);
});

it('deletes an existing accepted friendship when blocking a friend', function () {
    $me = actingAsUser();
    $friend = User::factory()->create();
    $friendship = Friendship::factory()->accepted()->create(['requester_id' => $me->id, 'recipient_id' => $friend->id]);

    $this->postJson('/api/friends/blocks', ['user_id' => $friend->id])->assertCreated();

    $this->assertDatabaseMissing('friendships', ['id' => $friendship->id]);
});

it('deletes a pending request in either direction when blocking', function () {
    $me = actingAsUser();
    $requester = User::factory()->create();
    $pending = Friendship::factory()->create(['requester_id' => $requester->id, 'recipient_id' => $me->id]);

    $this->postJson('/api/friends/blocks', ['user_id' => $requester->id])->assertCreated();

    $this->assertDatabaseMissing('friendships', ['id' => $pending->id]);
});

it('prevents the blocked user from sending a new request, with the same message as not being discoverable', function () {
    $me = actingAsUser();
    $blocked = User::factory()->create(['discoverable' => true]);
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blocked->id]);

    $forBlocked = $this->postJson('/api/friends/requests', ['user_id' => $blocked->id])->assertUnprocessable();

    $stranger = User::factory()->create(['discoverable' => false]);
    $forPrivate = $this->postJson('/api/friends/requests', ['user_id' => $stranger->id])->assertUnprocessable();

    expect($forBlocked->json('errors.user_id.0'))->toBe($forPrivate->json('errors.user_id.0'));
});

it('prevents sending a request to someone who blocked you, from the other direction too', function () {
    $me = actingAsUser();
    $blocker = User::factory()->create(['discoverable' => true]);
    UserBlock::factory()->create(['blocker_id' => $blocker->id, 'blocked_id' => $me->id]);

    $this->postJson('/api/friends/requests', ['user_id' => $blocker->id])->assertUnprocessable();
});

it('excludes a blocked user from search in either direction, with the same response as not being discoverable', function () {
    $me = actingAsUser();
    $blocked = User::factory()->create(['email' => 'blocked@example.com', 'discoverable' => true]);
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $blocked->id]);

    $forBlocked = $this->getJson('/api/friends/search?email=blocked@example.com')->assertOk();
    $forNonExistent = $this->getJson('/api/friends/search?email=nobody@example.com')->assertOk();

    expect($forBlocked->json())->toBe($forNonExistent->json());
});

it('lets a user unblock someone they blocked', function () {
    $me = actingAsUser();
    $target = User::factory()->create();
    $block = UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $target->id]);

    $this->deleteJson("/api/friends/blocks/{$block->id}")->assertNoContent();

    $this->assertDatabaseMissing('user_blocks', ['id' => $block->id]);
});

it('rejects unblocking a block that belongs to someone else', function () {
    actingAsUser();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $block = UserBlock::factory()->create(['blocker_id' => $a->id, 'blocked_id' => $b->id]);

    $this->deleteJson("/api/friends/blocks/{$block->id}")->assertForbidden();
});

it('lists only the users the authenticated user has blocked, not who blocked them', function () {
    $me = actingAsUser();
    $iBlocked = User::factory()->create(['name' => 'I Blocked Them']);
    $blockedMe = User::factory()->create(['name' => 'They Blocked Me']);
    UserBlock::factory()->create(['blocker_id' => $me->id, 'blocked_id' => $iBlocked->id]);
    UserBlock::factory()->create(['blocker_id' => $blockedMe->id, 'blocked_id' => $me->id]);

    $response = $this->getJson('/api/friends/blocks')->assertOk();

    $names = collect($response->json('data'))->pluck('user.name');
    expect($names)->toContain('I Blocked Them')->not->toContain('They Blocked Me');
});
