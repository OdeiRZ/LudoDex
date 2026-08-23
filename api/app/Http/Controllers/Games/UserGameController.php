<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Http\Requests\Games\StoreUserGameRequest;
use App\Http\Requests\Games\UpdateUserGameRequest;
use App\Http\Resources\UserGameResource;
use App\Models\Game;
use App\Models\UserGame;
use App\Services\GameTaxonomySyncer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserGameController extends Controller
{
    public function __construct(private readonly GameTaxonomySyncer $taxonomySyncer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $userGames = $request->user()
            ->games()
            ->with(['game.mechanics', 'game.categories', 'game.baseGame'])
            ->latest()
            ->get();

        return UserGameResource::collection($userGames);
    }

    public function store(StoreUserGameRequest $request): UserGameResource
    {
        $userGame = DB::transaction(function () use ($request) {
            // bgg_id is unique on games (has been since the very first
            // migration), so two different accounts manually adding the
            // same real BGG game - reachable any time "Rellenar desde BGG"
            // is used, not just an edge case - used to crash the second one
            // with a raw 500 (unhandled unique-constraint violation) instead
            // of either account ending up with a working collection entry.
            // Reuses the existing shared row instead of trying to insert a
            // duplicate; a game with no bgg_id (hand-typed, not looked up)
            // still always gets its own row, same as before.
            $bggId = $request->validated('bgg_id');
            $game = $bggId !== null ? Game::where('bgg_id', $bggId)->first() : null;

            if ($game !== null) {
                if ($request->user()->games()->where('game_id', $game->id)->exists()) {
                    throw ValidationException::withMessages([
                        'bgg_id' => [__('games.bgg_id_already_in_collection')],
                    ]);
                }
            } else {
                $game = Game::create($request->safe()->except(['mechanics', 'categories', 'status']));
                $this->taxonomySyncer->sync($game, $request->validated('mechanics', []), $request->validated('categories', []));
            }

            return $request->user()->games()->create([
                'game_id' => $game->id,
                'status' => $request->validated('status'),
            ]);
        });

        return new UserGameResource($userGame->load(['game.mechanics', 'game.categories', 'game.baseGame']));
    }

    public function update(UpdateUserGameRequest $request, UserGame $userGame): UserGameResource
    {
        $this->authorize('update', $userGame);

        DB::transaction(function () use ($request, $userGame) {
            $gameAttributes = $request->safe()->except(['mechanics', 'categories', 'status']);

            if ($gameAttributes !== []) {
                $userGame->game->update($gameAttributes);
            }

            if ($request->has('mechanics') || $request->has('categories')) {
                $this->taxonomySyncer->sync(
                    $userGame->game,
                    $request->validated('mechanics', $userGame->game->mechanics->pluck('name')->all()),
                    $request->validated('categories', $userGame->game->categories->pluck('name')->all()),
                );
            }

            $userGame->update($request->safe()->only(['status']));
        });

        return new UserGameResource($userGame->load(['game.mechanics', 'game.categories', 'game.baseGame']));
    }

    public function destroy(UserGame $userGame): Response
    {
        $this->authorize('delete', $userGame);

        $userGame->delete();

        return response()->noContent();
    }

    /**
     * Wipes the current user's entire collection in one go - a reset for
     * whoever wants to start over (e.g. before a clean re-import) rather
     * than removing each entry by hand. Only deletes the user_games rows
     * that link this user to a game and its status; the underlying
     * Game rows are a catalog shared across every user, so other people's
     * collections and the game data itself (mechanics, categories, BGG
     * metadata...) are untouched.
     */
    public function clear(Request $request): Response
    {
        $request->user()->games()->delete();

        return response()->noContent();
    }
}
