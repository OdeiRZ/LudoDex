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
            // Manual entry always creates its own Game row (no BGG id to
            // dedupe against yet - that arrives with BGG import). Two users
            // adding "the same" obscure game by hand independently ending up
            // as two separate rows is an accepted MVP simplification.
            $game = Game::create($request->safe()->except(['mechanics', 'categories', 'status', 'notes']));

            $this->taxonomySyncer->sync($game, $request->validated('mechanics', []), $request->validated('categories', []));

            return $request->user()->games()->create([
                'game_id' => $game->id,
                'status' => $request->validated('status'),
                'notes' => $request->validated('notes'),
            ]);
        });

        return new UserGameResource($userGame->load(['game.mechanics', 'game.categories', 'game.baseGame']));
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
                $this->taxonomySyncer->sync(
                    $userGame->game,
                    $request->validated('mechanics', $userGame->game->mechanics->pluck('name')->all()),
                    $request->validated('categories', $userGame->game->categories->pluck('name')->all()),
                );
            }

            $userGame->update($request->safe()->only(['status', 'notes']));
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
     * that link this user to a game and its status/notes; the underlying
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
