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
            // The edit form always resubmits every field, changed or not (it
            // has no per-field dirty tracking), so checking presence alone
            // - the previous behaviour - flagged a untouched save as
            // "touching" the shared Game row just as much as a real edit,
            // gating it behind updateGame even when nothing would actually
            // change. Reported live: opening the very first game in a
            // collection and hitting "Save" with zero edits threw "This
            // action is unauthorized." as soon as a second account also had
            // that game - the updateGame policy (see its own docblock) was
            // correctly blocking catalog edits from a non-sole owner, but a
            // no-op resubmission isn't an edit. Comparing against the
            // current values instead means only a genuine change triggers
            // the gate.
            $gameAttributes = $request->safe()->except(['mechanics', 'categories', 'status']);
            $changedGameAttributes = collect($gameAttributes)
                ->reject(fn ($value, $key) => $userGame->game->{$key} === $value)
                ->all();

            $currentMechanics = $userGame->game->mechanics->pluck('name')->all();
            $currentCategories = $userGame->game->categories->pluck('name')->all();
            $mechanicsChanged = $request->has('mechanics')
                && $this->namesDiffer($request->validated('mechanics'), $currentMechanics);
            $categoriesChanged = $request->has('categories')
                && $this->namesDiffer($request->validated('categories'), $currentCategories);

            $touchesGame = $changedGameAttributes !== [] || $mechanicsChanged || $categoriesChanged;

            if ($touchesGame) {
                $this->authorize('updateGame', $userGame);
            }

            if ($changedGameAttributes !== []) {
                $userGame->game->update($changedGameAttributes);
            }

            if ($mechanicsChanged || $categoriesChanged) {
                $this->taxonomySyncer->sync(
                    $userGame->game,
                    $request->validated('mechanics', $currentMechanics),
                    $request->validated('categories', $currentCategories),
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

    /**
     * Order-independent comparison - the form re-sends the tag list in
     * whatever order TagInput currently holds it, which needn't match
     * insertion order in the pivot table, so a plain array/order-sensitive
     * compare would flag an unchanged set as "changed" just as easily as
     * the bug this whole diff-before-touching approach is fixing for the
     * plain Game columns above.
     *
     * @param  array<int, string>  $incoming
     * @param  array<int, string>  $current
     */
    private function namesDiffer(array $incoming, array $current): bool
    {
        sort($incoming);
        sort($current);

        return $incoming !== $current;
    }
}
