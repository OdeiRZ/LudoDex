<?php

use App\Models\Game;
use Illuminate\Support\Facades\Http;

it('rejects unauthenticated access', function () {
    $game = Game::factory()->create(['description' => 'Some text.']);

    $this->postJson("/api/games/{$game->id}/translate-description")->assertUnauthorized();
});

it('translates and stores description_es when a translation succeeds', function () {
    actingAsUser();
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => Http::response(['translations' => [['text' => 'Un texto.']]]));

    $game = Game::factory()->create(['description' => 'Some text.', 'description_es' => null]);

    $response = $this->postJson("/api/games/{$game->id}/translate-description");

    $response->assertOk()->assertJson(['description_es' => 'Un texto.']);
    expect($game->fresh()->description_es)->toBe('Un texto.');
});

it('does not call DeepL again for a game that already has a translation', function () {
    actingAsUser();
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => Http::response('should not be called', 500));

    $game = Game::factory()->create(['description' => 'Some text.', 'description_es' => 'Ya traducido.']);

    $response = $this->postJson("/api/games/{$game->id}/translate-description");

    $response->assertOk()->assertJson(['description_es' => 'Ya traducido.']);
    Http::assertNothingSent();
});

it('leaves description_es null instead of failing when the game has no description at all', function () {
    actingAsUser();
    config(['deepl.api_key' => 'fake-key:fx']);
    Http::fake(fn () => Http::response('should not be called', 500));

    $game = Game::factory()->create(['description' => null, 'description_es' => null]);

    $response = $this->postJson("/api/games/{$game->id}/translate-description");

    $response->assertOk()->assertJson(['description_es' => null]);
    Http::assertNothingSent();
});

it('leaves description_es null instead of failing when DeepL is not configured', function () {
    actingAsUser();
    config(['deepl.api_key' => null]);

    $game = Game::factory()->create(['description' => 'Some text.', 'description_es' => null]);

    $response = $this->postJson("/api/games/{$game->id}/translate-description");

    $response->assertOk()->assertJson(['description_es' => null]);
    expect($game->fresh()->description_es)->toBeNull();
});
