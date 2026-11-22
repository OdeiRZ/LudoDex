<?php

use App\Models\Game;
use App\Models\User;

it('rejects unauthenticated access', function () {
    $this->postJson('/api/games/backfill-translations', ['games' => []])->assertUnauthorized();
});

it('rejects an authenticated user who is not the configured owner', function () {
    config(['app.owner_email' => 'owner@example.com']);
    $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']), 'sanctum');

    $game = Game::factory()->create(['bgg_id' => 1, 'description_es' => null]);

    $response = $this->postJson('/api/games/backfill-translations', [
        'games' => [['bgg_id' => 1, 'description_es' => 'Un texto.']],
    ]);

    $response->assertForbidden();
    expect($game->fresh()->description_es)->toBeNull();
});

it('fills in description_es for the configured owner', function () {
    config(['app.owner_email' => 'owner@example.com']);
    $this->actingAs(User::factory()->create(['email' => 'owner@example.com']), 'sanctum');

    $game = Game::factory()->create(['bgg_id' => 1, 'description_es' => null]);

    $response = $this->postJson('/api/games/backfill-translations', [
        'games' => [['bgg_id' => 1, 'description_es' => 'Un texto.']],
    ]);

    $response->assertOk()->assertJson(['updated' => 1]);
    expect($game->fresh()->description_es)->toBe('Un texto.');
});

it('never overwrites a translation that already exists', function () {
    config(['app.owner_email' => 'owner@example.com']);
    $this->actingAs(User::factory()->create(['email' => 'owner@example.com']), 'sanctum');

    $game = Game::factory()->create(['bgg_id' => 1, 'description_es' => 'Ya traducido.']);

    $response = $this->postJson('/api/games/backfill-translations', [
        'games' => [['bgg_id' => 1, 'description_es' => 'Otro texto.']],
    ]);

    $response->assertOk()->assertJson(['updated' => 0]);
    expect($game->fresh()->description_es)->toBe('Ya traducido.');
});

it('lists the existing translations for the owner only', function () {
    config(['app.owner_email' => 'owner@example.com']);
    Game::factory()->create(['bgg_id' => 1, 'description_es' => 'Uno.']);
    Game::factory()->create(['bgg_id' => 2, 'description_es' => null]);
    Game::factory()->create(['bgg_id' => null, 'description_es' => 'Sin BGG.']);

    $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']), 'sanctum');
    $this->getJson('/api/games/translations')->assertForbidden();

    $this->actingAs(User::factory()->create(['email' => 'owner@example.com']), 'sanctum');
    $this->getJson('/api/games/translations')
        ->assertOk()
        ->assertExactJson(['data' => [['bgg_id' => 1, 'description_es' => 'Uno.']]]);
});

it('does not list translations to a guest', function () {
    $this->getJson('/api/games/translations')->assertUnauthorized();
});
