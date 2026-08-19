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
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plays = $request->user()
            ->plays()
            ->with('game')
            ->orderByDesc('played_at')
            ->paginate(20);

        return PlayResource::collection($plays);
    }
}
