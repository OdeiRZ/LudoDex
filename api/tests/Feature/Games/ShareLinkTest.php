<?php

use App\Models\ShareLink;
use App\Models\User;

it('rejects unauthenticated access to every share link route', function () {
    $this->getJson('/api/share-link')->assertUnauthorized();
    $this->postJson('/api/share-link')->assertUnauthorized();
    $this->deleteJson('/api/share-link')->assertUnauthorized();
});

it('returns null when the user has not shared anything yet', function () {
    actingAsUser();

    $this->getJson('/api/share-link')->assertOk()->assertExactJson(['data' => null]);
});

it('creates a link that expires in 7 days by default', function () {
    $user = actingAsUser();

    $response = $this->postJson('/api/share-link')->assertCreated();

    $token = $response->json('data.token');
    expect(ShareLink::isWellFormedToken($token))->toBeTrue();
    expect($response->json('data.expired'))->toBeFalse();
    expect($response->json('data.views_count'))->toBe(0);
    expect(now()->addDays(7)->diffInMinutes($user->fresh()->shareLink->expires_at, absolute: true))->toBeLessThan(2);
});

it('lets the user pick 30 or 90 days, or no expiry at all', function (?int $days) {
    $user = actingAsUser();

    $this->postJson('/api/share-link', ['expires_in_days' => $days])->assertCreated();

    $expiresAt = $user->fresh()->shareLink->expires_at;
    if ($days === null) {
        expect($expiresAt)->toBeNull();
    } else {
        expect(now()->addDays($days)->diffInMinutes($expiresAt, absolute: true))->toBeLessThan(2);
    }
})->with([30, 90, null]);

it('rejects an expiry that is not one of the offered ones', function () {
    actingAsUser();

    $this->postJson('/api/share-link', ['expires_in_days' => 3650])->assertUnprocessable();
    $this->postJson('/api/share-link', ['expires_in_days' => 'abc'])->assertUnprocessable();
});

it('regenerates the link: new token, counters reset, and the old token stops working', function () {
    $user = actingAsUser();
    $old = ShareLink::factory()->for($user)->create(['views_count' => 5, 'last_viewed_at' => now()]);
    $oldToken = $old->token;

    $response = $this->postJson('/api/share-link')->assertCreated();

    expect($response->json('data.token'))->not->toBe($oldToken);
    expect($response->json('data.views_count'))->toBe(0);
    expect($response->json('data.last_viewed_at'))->toBeNull();
    expect(ShareLink::where('user_id', $user->id)->count())->toBe(1);
    $this->getJson("/api/public/collections/{$oldToken}")->assertNotFound();
});

it('shows the current link, with its views', function () {
    $user = actingAsUser();
    $link = ShareLink::factory()->for($user)->create(['views_count' => 3]);

    $this->getJson('/api/share-link')
        ->assertOk()
        ->assertJsonPath('data.token', $link->token)
        ->assertJsonPath('data.views_count', 3)
        ->assertJsonPath('data.expired', false);
});

it('marks an expired link as expired in the management screen', function () {
    $user = actingAsUser();
    ShareLink::factory()->expired()->for($user)->create();

    $this->getJson('/api/share-link')->assertOk()->assertJsonPath('data.expired', true);
});

it('stops sharing: deletes the link and the public URL answers 404', function () {
    $user = actingAsUser();
    $link = ShareLink::factory()->for($user)->create();

    $this->deleteJson('/api/share-link')->assertNoContent();

    expect(ShareLink::count())->toBe(0);
    $this->getJson("/api/public/collections/{$link->token}")->assertNotFound();
});

it('never touches another user\'s link', function () {
    $other = User::factory()->create();
    $theirs = ShareLink::factory()->for($other)->create();
    actingAsUser();

    $this->getJson('/api/share-link')->assertOk()->assertJsonPath('data', null);
    $this->deleteJson('/api/share-link')->assertNoContent();
    $this->postJson('/api/share-link')->assertCreated();

    expect($theirs->fresh()->token)->toBe($theirs->token);
});
