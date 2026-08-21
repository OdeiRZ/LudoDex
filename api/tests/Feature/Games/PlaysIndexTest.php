<?php

use App\Models\Game;
use App\Models\Play;
use App\Models\User;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/plays')->assertUnauthorized();
});

it('lists no plays for a fresh user', function () {
    actingAsUser();

    $this->getJson('/api/plays')->assertOk()->assertJson(['data' => []]);
});

it('lists only the authenticated user\'s own plays, most recent first', function () {
    $user = actingAsUser();
    $otherUser = User::factory()->create();

    $catan = Game::factory()->create(['name' => 'Catan', 'bgg_id' => 13]);
    $sevenWonders = Game::factory()->create(['name' => '7 Wonders', 'bgg_id' => 68448]);

    Play::factory()->for($user)->for($catan)->create(['played_at' => '2026-01-01']);
    Play::factory()->for($user)->for($sevenWonders)->create(['played_at' => '2026-02-01']);
    Play::factory()->for($otherUser)->for($catan)->create(['played_at' => '2026-03-01']);

    $response = $this->getJson('/api/plays')->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.game.name', '7 Wonders')
        ->assertJsonPath('data.0.played_at', '2026-02-01')
        ->assertJsonPath('data.1.game.name', 'Catan');
});

it('includes quantity and duration in the response shape', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($user)->for($game)->create([
        'quantity' => 3,
        'duration_minutes' => 45,
    ]);

    $this->getJson('/api/plays')->assertOk()
        ->assertJsonPath('data.0.quantity', 3)
        ->assertJsonPath('data.0.duration_minutes', 45);
});

it('includes the game\'s description so the play list can open its detail modal', function () {
    $user = actingAsUser();
    $game = Game::factory()->create([
        'bgg_id' => 13,
        'description' => 'Trade and build on the island of Catan.',
        'description_es' => 'Comercia y construye en la isla de Catan.',
    ]);

    Play::factory()->for($user)->for($game)->create();

    $this->getJson('/api/plays')->assertOk()
        ->assertJsonPath('data.0.game.description', 'Trade and build on the island of Catan.')
        ->assertJsonPath('data.0.game.description_es', 'Comercia y construye en la isla de Catan.');
});

it('filters by the played game\'s own name via ?search=, case-insensitively', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['name' => 'Catan', 'bgg_id' => 13]);
    $catanExpansion = Game::factory()->create(['name' => 'Catan: Seafarers', 'bgg_id' => 14]);
    $sevenWonders = Game::factory()->create(['name' => '7 Wonders', 'bgg_id' => 68448]);

    Play::factory()->for($user)->for($catan)->create();
    Play::factory()->for($user)->for($catanExpansion)->create();
    Play::factory()->for($user)->for($sevenWonders)->create();

    $response = $this->getJson('/api/plays?search=catan')->assertOk();

    $response->assertJsonCount(2, 'data');
    $names = collect($response->json('data'))->pluck('game.name');
    expect($names)->toContain('Catan')->toContain('Catan: Seafarers')->not->toContain('7 Wonders');
});

it('returns no plays when the search does not match any played game', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['name' => 'Catan', 'bgg_id' => 13]);
    Play::factory()->for($user)->for($game)->create();

    $this->getJson('/api/plays?search=nonexistent')->assertOk()->assertJson(['data' => []]);
});

it('ignores an empty ?search= and returns every play', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13]);
    Play::factory()->for($user)->for($game)->create();

    $this->getJson('/api/plays?search=')->assertOk()->assertJsonCount(1, 'data');
});

it('paginates at 20 per page', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($user)->for($game)->count(25)->create();

    $response = $this->getJson('/api/plays')->assertOk();

    $response->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);
});
