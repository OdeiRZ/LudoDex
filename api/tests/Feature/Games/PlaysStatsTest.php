<?php

use App\Models\Game;
use App\Models\Play;
use App\Models\User;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/plays/stats')->assertUnauthorized();
});

it('returns zeroed stats and an empty top_played for a fresh user', function () {
    actingAsUser();

    $this->getJson('/api/plays/stats')->assertOk()->assertJson([
        'data' => [
            'total_plays' => 0,
            'distinct_games' => 0,
            'total_minutes' => 0,
            'duration_known_plays' => 0,
            'top_played' => [],
        ],
    ]);
});

it('sums quantity across plays for total_plays, counts distinct games', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['bgg_id' => 13]);
    $sevenWonders = Game::factory()->create(['bgg_id' => 68448]);

    Play::factory()->for($user)->for($catan)->create(['quantity' => 3]);
    Play::factory()->for($user)->for($catan)->create(['quantity' => 2]);
    Play::factory()->for($user)->for($sevenWonders)->create(['quantity' => 1]);

    $this->getJson('/api/plays/stats')->assertOk()
        ->assertJsonPath('data.total_plays', 6)
        ->assertJsonPath('data.distinct_games', 2);
});

it('multiplies quantity by duration_minutes for total_minutes, skipping unknown durations', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($user)->for($game)->create(['quantity' => 2, 'duration_minutes' => 30]);
    Play::factory()->for($user)->for($game)->create(['quantity' => 1, 'duration_minutes' => null]);

    $this->getJson('/api/plays/stats')->assertOk()
        ->assertJsonPath('data.total_minutes', 60)
        ->assertJsonPath('data.duration_known_plays', 2);
});

it('ranks top_played by summed quantity, not row count', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['name' => 'Catan', 'bgg_id' => 13, 'image_url' => 'https://example.test/catan.jpg']);
    $sevenWonders = Game::factory()->create(['name' => '7 Wonders', 'bgg_id' => 68448]);

    // Catan: a single row bundling 5 same-day plays into one quantity.
    Play::factory()->for($user)->for($catan)->create(['quantity' => 5]);
    // 7 Wonders: three separate rows, quantity 1 each - more rows than
    // Catan, but fewer total plays (3 vs 5), so it should still rank below.
    Play::factory()->for($user)->for($sevenWonders)->count(3)->create(['quantity' => 1]);

    $this->getJson('/api/plays/stats')->assertOk()
        ->assertJsonCount(2, 'data.top_played')
        ->assertJsonPath('data.top_played.0.game.name', 'Catan')
        ->assertJsonPath('data.top_played.0.game.image_url', 'https://example.test/catan.jpg')
        ->assertJsonPath('data.top_played.0.count', 5)
        ->assertJsonPath('data.top_played.1.game.name', '7 Wonders')
        ->assertJsonPath('data.top_played.1.count', 3);
});

it('caps top_played at 3 games even with more distinct games played', function () {
    $user = actingAsUser();

    foreach ([['A', 5], ['B', 4], ['C', 3], ['D', 2], ['E', 1]] as [$name, $quantity]) {
        $game = Game::factory()->create(['name' => $name, 'bgg_id' => crc32($name)]);
        Play::factory()->for($user)->for($game)->create(['quantity' => $quantity]);
    }

    $response = $this->getJson('/api/plays/stats')->assertOk();

    $response->assertJsonCount(3, 'data.top_played');
    $names = collect($response->json('data.top_played'))->pluck('game.name');
    expect($names->all())->toBe(['A', 'B', 'C']);
});

it('only counts the authenticated user\'s own plays', function () {
    $user = actingAsUser();
    $otherUser = User::factory()->create();
    $game = Game::factory()->create(['bgg_id' => 13]);

    Play::factory()->for($user)->for($game)->create(['quantity' => 1]);
    Play::factory()->for($otherUser)->for($game)->create(['quantity' => 10]);

    $this->getJson('/api/plays/stats')->assertOk()
        ->assertJsonPath('data.total_plays', 1)
        ->assertJsonPath('data.top_played.0.count', 1);
});
