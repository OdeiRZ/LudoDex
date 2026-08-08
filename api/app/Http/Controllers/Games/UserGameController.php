<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Http\Requests\Games\StoreUserGameRequest;
use App\Http\Requests\Games\UpdateUserGameRequest;
use App\Http\Resources\UserGameResource;
use App\Models\Category;
use App\Models\Game;
use App\Models\Mechanic;
use App\Models\UserGame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class UserGameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $userGames = $request->user()
            ->games()
            ->with(['game.mechanics', 'game.categories'])
            ->latest()
            ->get();

        return UserGameResource::collection($userGames);
    }

    public function store(StoreUserGameRequest $request): UserGameResource
    {
        $userGame = DB::transaction(function () use ($request) {
            // Manual entry always creates its own Game row (no BGG id to
            // dedupe against yet - that arrives with BGG import). Two users
            // adding "the same" obscure game by hand independently ending up
            // as two separate rows is an accepted MVP simplification.
            $game = Game::create($request->safe()->except(['mechanics', 'categories', 'status', 'notes']));

            $this->syncTaxonomies($game, $request->validated('mechanics', []), $request->validated('categories', []));

            return $request->user()->games()->create([
                'game_id' => $game->id,
                'status' => $request->validated('status'),
                'notes' => $request->validated('notes'),
            ]);
        });

        return new UserGameResource($userGame->load(['game.mechanics', 'game.categories']));
    }

    public function update(UpdateUserGameRequest $request, UserGame $userGame): UserGameResource
    {
        $this->authorize('update', $userGame);

        DB::transaction(function () use ($request, $userGame) {
            $gameAttributes = $request->safe()->except(['mechanics', 'categories', 'status', 'notes']);

            if ($gameAttributes !== []) {
                $userGame->game->update($gameAttributes);
            }

            if ($request->has('mechanics') || $request->has('categories')) {
                $this->syncTaxonomies(
                    $userGame->game,
                    $request->validated('mechanics', $userGame->game->mechanics->pluck('name')->all()),
                    $request->validated('categories', $userGame->game->categories->pluck('name')->all()),
                );
            }

            $userGame->update($request->safe()->only(['status', 'notes']));
        });

        return new UserGameResource($userGame->load(['game.mechanics', 'game.categories']));
    }

    public function destroy(UserGame $userGame): Response
    {
        $this->authorize('delete', $userGame);

        $userGame->delete();

        return response()->noContent();
    }

    /**
     * @param  array<int, string>  $mechanicNames
     * @param  array<int, string>  $categoryNames
     */
    private function syncTaxonomies(Game $game, array $mechanicNames, array $categoryNames): void
    {
        $mechanicIds = collect($mechanicNames)
            ->map(fn (string $name) => Mechanic::firstOrCreate(['name' => $name])->id);

        $categoryIds = collect($categoryNames)
            ->map(fn (string $name) => Category::firstOrCreate(['name' => $name])->id);

        $game->mechanics()->sync($mechanicIds);
        $game->categories()->sync($categoryIds);
    }
}
