<?php

namespace App\Http\Controllers\Friends;

use App\Http\Controllers\Controller;
use App\Http\Resources\FriendResource;
use App\Http\Resources\PlayResource;
use App\Services\Friends\FriendshipService;
use App\Services\Games\PlayStatsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FriendPlayController extends Controller
{
    public function __construct(
        private readonly FriendshipService $friendshipService,
        private readonly PlayStatsCalculator $playStatsCalculator,
    ) {}

    /**
     * Same query/pagination/search shape as PlayController::index(), just
     * scoped to the friend's plays() instead of the authenticated user's -
     * see that method's own docblock for why it's paginated and
     * server-side searched. Route param is a plain int, not an
     * implicitly-bound User - see
     * FriendshipService::resolveAcceptedFriend()'s own docblock for why.
     */
    public function index(Request $request, int $friend): AnonymousResourceCollection
    {
        $friendUser = $this->friendshipService->resolveAcceptedFriend($request->user(), $friend);

        // See FriendCollectionController::index()'s own comment for why
        // this is a 403, not a 404.
        abort_if(! $friendUser->share_plays, 403, __('friends.plays_not_shared'));

        $plays = $friendUser->plays()
            ->with('game.baseGame')
            ->when(
                $request->filled('search'),
                fn ($query) => $query->whereHas(
                    'game',
                    fn ($gameQuery) => $gameQuery->where('name', 'like', '%'.$request->string('search').'%'),
                ),
            )
            ->orderByDesc('played_at')
            ->paginate(20);

        // additional() adds `friend` as a sibling of `data`/`links`/`meta`
        // (data here is the paginated plays array itself) - lets the view
        // show whose plays these are without a second round trip.
        return PlayResource::collection($plays)->additional(['friend' => new FriendResource($friendUser)]);
    }

    public function stats(Request $request, int $friend): JsonResponse
    {
        $friendUser = $this->friendshipService->resolveAcceptedFriend($request->user(), $friend);

        abort_if(! $friendUser->share_plays, 403, __('friends.plays_not_shared'));

        return response()->json([
            'data' => $this->playStatsCalculator->calculate($friendUser),
            'friend' => new FriendResource($friendUser),
        ]);
    }
}
