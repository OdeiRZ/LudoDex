<?php

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use App\Models\User;
use App\Models\UserGame;
use Illuminate\Support\Str;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/games')->assertUnauthorized();
});

it('lists an empty collection for a fresh user', function () {
    actingAsUser();

    $this->getJson('/api/games')->assertOk()->assertJson(['data' => []]);
});

it('creates a game with mechanics and categories and adds it to the collection', function () {
    actingAsUser();

    $response = $this->postJson('/api/games', [
        'name' => 'Catan',
        'min_players' => 3,
        'max_players' => 4,
        'min_playtime_minutes' => 60,
        'max_playtime_minutes' => 90,
        'weight' => 2.3,
        'is_competitive' => true,
        'mechanics' => ['Trading', 'Dice Rolling'],
        'categories' => ['Negotiation'],
        'status' => 'owned',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'owned')
        ->assertJsonPath('data.game.name', 'Catan')
        ->assertJsonPath('data.game.min_players', 3)
        ->assertJsonCount(2, 'data.game.mechanics')
        ->assertJsonCount(1, 'data.game.categories');

    $this->assertDatabaseHas('games', ['name' => 'Catan']);
    $this->assertDatabaseHas('mechanics', ['name' => 'Trading']);
    $this->assertDatabaseHas('categories', ['name' => 'Negotiation']);
});

it('reuses an existing mechanic instead of duplicating it', function () {
    $user = actingAsUser();

    $this->postJson('/api/games', [
        'name' => 'Game A',
        'mechanics' => ['Drafting'],
        'categories' => [],
        'status' => 'owned',
    ])->assertCreated();

    $this->postJson('/api/games', [
        'name' => 'Game B',
        'mechanics' => ['Drafting'],
        'categories' => [],
        'status' => 'owned',
    ])->assertCreated();

    expect(Mechanic::where('name', 'Drafting')->count())->toBe(1);
    expect($user->games()->count())->toBe(2);
});

it('rejects a max_players lower than min_players', function () {
    actingAsUser();

    $this->postJson('/api/games', [
        'name' => 'Broken',
        'min_players' => 5,
        'max_players' => 2,
        'status' => 'owned',
    ])->assertUnprocessable()->assertJsonValidationErrors('max_players');
});

it('lists only the current user collection', function () {
    $user = actingAsUser();
    $otherUser = User::factory()->create();

    UserGame::factory()->for($user)->create();
    UserGame::factory()->for($otherUser)->create();

    $response = $this->getJson('/api/games');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('updates the status of an owned game', function () {
    $user = actingAsUser();
    $userGame = UserGame::factory()->for($user)->create(['status' => 'wishlist']);

    $this->putJson("/api/games/{$userGame->id}", [
        'status' => 'owned',
    ])->assertOk()
        ->assertJsonPath('data.status', 'owned');
});

it('updates the underlying game fields and taxonomies', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['name' => 'Old Name']);
    $userGame = UserGame::factory()->for($user)->for($game)->create();

    $this->putJson("/api/games/{$userGame->id}", [
        'name' => 'New Name',
        'mechanics' => ['Deck Building'],
    ])->assertOk()
        ->assertJsonPath('data.game.name', 'New Name')
        ->assertJsonPath('data.game.mechanics', ['Deck Building']);
});

it('sets the base game when creating a game as an expansion of one already in the collection', function () {
    actingAsUser();
    $catan = Game::factory()->create(['name' => 'Catan']);

    $response = $this->postJson('/api/games', [
        'name' => 'Catan: Seafarers',
        'base_game_id' => $catan->id,
        'mechanics' => [],
        'categories' => [],
        'status' => 'owned',
    ]);

    $response->assertCreated()->assertJsonPath('data.game.base_game_id', $catan->id);
});

it('rejects a base_game_id that does not exist', function () {
    actingAsUser();

    $this->postJson('/api/games', [
        'name' => 'Catan: Seafarers',
        'base_game_id' => (string) Str::ulid(),
        'mechanics' => [],
        'categories' => [],
        'status' => 'owned',
    ])->assertUnprocessable()->assertJsonValidationErrors('base_game_id');
});

it('sets and later clears the base game on an existing game', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['name' => 'Catan']);
    $seafarers = Game::factory()->create(['name' => 'Catan: Seafarers']);
    $userGame = UserGame::factory()->for($user)->for($seafarers)->create();

    $this->putJson("/api/games/{$userGame->id}", ['base_game_id' => $catan->id])
        ->assertOk()
        ->assertJsonPath('data.game.base_game_id', $catan->id);

    $this->putJson("/api/games/{$userGame->id}", ['base_game_id' => null])
        ->assertOk()
        ->assertJsonPath('data.game.base_game_id', null);
});

it('rejects setting a game as its own expansion', function () {
    $user = actingAsUser();
    $game = Game::factory()->create(['name' => 'Catan']);
    $userGame = UserGame::factory()->for($user)->for($game)->create();

    $this->putJson("/api/games/{$userGame->id}", ['base_game_id' => $game->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('base_game_id');
});

it('forbids updating another user\'s game entry', function () {
    actingAsUser();
    $otherUser = User::factory()->create();
    $userGame = UserGame::factory()->for($otherUser)->create();

    $this->putJson("/api/games/{$userGame->id}", ['status' => 'owned'])
        ->assertForbidden();
});

it('deletes a game from the collection', function () {
    $user = actingAsUser();
    $userGame = UserGame::factory()->for($user)->create();

    $this->deleteJson("/api/games/{$userGame->id}")->assertNoContent();

    $this->assertDatabaseMissing('user_games', ['id' => $userGame->id]);
});

it('forbids deleting another user\'s game entry', function () {
    actingAsUser();
    $otherUser = User::factory()->create();
    $userGame = UserGame::factory()->for($otherUser)->create();

    $this->deleteJson("/api/games/{$userGame->id}")->assertForbidden();
});

it('clears the entire collection in one request', function () {
    $user = actingAsUser();
    UserGame::factory()->for($user)->count(3)->create();

    $this->deleteJson('/api/games')->assertNoContent();

    expect($user->games()->count())->toBe(0);
});

it('clearing the collection only affects the current user, not the underlying games or other users', function () {
    $user = actingAsUser();
    $otherUser = User::factory()->create();

    $game = Game::factory()->create();
    UserGame::factory()->for($user)->for($game)->create();
    $otherUserGame = UserGame::factory()->for($otherUser)->create();

    $this->deleteJson('/api/games')->assertNoContent();

    expect($user->games()->count())->toBe(0);
    expect($otherUser->games()->count())->toBe(1);
    $this->assertDatabaseHas('user_games', ['id' => $otherUserGame->id]);
    $this->assertDatabaseHas('games', ['id' => $game->id]);
});

it('rejects unauthenticated requests to clear the collection', function () {
    $this->deleteJson('/api/games')->assertUnauthorized();
});

it('includes the base game name on an expansion, resolved via base_game_id', function () {
    $user = actingAsUser();
    $catan = Game::factory()->create(['name' => 'Catan']);
    $seafarers = Game::factory()->create(['name' => 'Catan: Seafarers', 'base_game_id' => $catan->id]);
    UserGame::factory()->for($user)->for($seafarers)->create();

    $response = $this->getJson('/api/games');

    $response->assertOk()->assertJsonPath('data.0.game.base_game_name', 'Catan');
});

it('lists the mechanics and categories catalog', function () {
    actingAsUser();
    Mechanic::factory()->count(3)->create();
    Category::factory()->count(2)->create();

    $this->getJson('/api/mechanics')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/categories')->assertOk()->assertJsonCount(2, 'data');
});
