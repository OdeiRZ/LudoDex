<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlayResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayController extends Controller
{
    /**
     * Paginated, unlike GET /games: a full collection tops out around what
     * one person can plausibly own, but a full play history routinely runs
     * to thousands of rows once imported (see BggPlaysImportController).
     *
     * Optional ?search= filters by the played game's own name - asked for
     * directly, to answer "when did I last play X" without scrolling a
     * long history for it. Server-side (not the client-side filtering
     * DashboardView/PickerView do over their own already-fully-loaded
     * list) since this one's paginated - filtering only the current page
     * client-side would miss matches sitting on a page not loaded yet.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plays = $request->user()
            ->plays()
            ->with('game')
            ->when(
                $request->filled('search'),
                fn ($query) => $query->whereHas(
                    'game',
                    fn ($gameQuery) => $gameQuery->where('name', 'like', '%'.$request->string('search').'%'),
                ),
            )
            ->orderByDesc('played_at')
            ->paginate(20);

        return PlayResource::collection($plays);
    }
}
