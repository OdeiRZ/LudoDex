<?php

use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use App\Services\GameTaxonomySyncer;

it('sync() replaces the full list, dropping a tag no longer submitted', function () {
    $game = Game::factory()->create();
    $game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Drafting']));

    (new GameTaxonomySyncer)->sync($game, ['Deck Building'], []);

    expect($game->mechanics->pluck('name')->all())->toBe(['Deck Building']);
});

it('syncFromBgg() adds BGG-reported tags without dropping ones already attached', function () {
    $game = Game::factory()->create();
    $game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Custom Homebrew Mechanic']));
    $game->categories()->attach(Category::factory()->create(['name' => 'Custom Homebrew Category']));

    (new GameTaxonomySyncer)->syncFromBgg($game, ['Worker Placement'], ['Strategy']);

    expect($game->mechanics->pluck('name')->sort()->values()->all())
        ->toBe(['Custom Homebrew Mechanic', 'Worker Placement'])
        ->and($game->categories->pluck('name')->sort()->values()->all())
        ->toBe(['Custom Homebrew Category', 'Strategy']);
});

it('syncFromBgg() does not duplicate a tag the game already has', function () {
    $game = Game::factory()->create();
    $game->mechanics()->attach(Mechanic::factory()->create(['name' => 'Worker Placement']));

    (new GameTaxonomySyncer)->syncFromBgg($game, ['Worker Placement'], []);

    expect($game->mechanics()->count())->toBe(1);
});
