<?php

namespace App\Http\Controllers\Friends;

use App\Http\Controllers\Controller;
use App\Http\Resources\FriendResource;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\Friends\FriendshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FriendCollectionController extends Controller
{
    public function __construct(private readonly FriendshipService $friendshipService) {}

    /**
     * Only compares `status = 'owned'` on both sides - a wishlist entry
     * says nothing about what the two of you can actually play together,
     * and mixing it in would just add ambiguity (see Fase 2 scope
     * decision). Games are already deduplicated by `bgg_id` in the shared
     * catalog (see UserGameController::store()), so two users owning the
     * same game share the same `game_id` - the comparison is a plain id
     * intersect/diff, no fuzzy matching needed.
     *
     * Route param is a plain int, not an implicitly-bound User - see
     * FriendshipService::resolveAcceptedFriend()'s own docblock for why.
     */
    public function index(Request $request, int $friend): JsonResponse
    {
        $me = $request->user();
        $friendUser = $this->friendshipService->resolveAcceptedFriend($me, $friend);

        // A 403 here, not a 404: unlike resolveAcceptedFriend()'s 404
        // (which protects whether you're even friends at all), reaching
        // this point already means you are - something the viewer already
        // knows independently (this friend is in their own friends list),
        // so there's no oracle to protect by hiding it behind a generic
        // not-found. A distinguishable error also lets the frontend show
        // "not shared" on just this data type without hiding the rest of
        // the friend's page (see FriendDetailView.vue / friendDetail.ts).
        abort_if(! $friendUser->share_collection, 403, __('friends.collection_not_shared'));

        $myGameIds = $me->games()->where('status', 'owned')->pluck('game_id');
        $theirGameIds = $friendUser->games()->where('status', 'owned')->pluck('game_id');

        $sharedIds = $myGameIds->intersect($theirGameIds);
        $mineOnlyIds = $myGameIds->diff($theirGameIds);
        $theirsOnlyIds = $theirGameIds->diff($myGameIds);

        // orderBy('name') here, not sorted again per bucket below:
        // Collection::only() preserves the source collection's own order,
        // so one already-sorted query is enough for all three lists to
        // come out alphabetical.
        $gamesById = Game::with(['mechanics', 'categories', 'baseGame'])
            ->whereIn('id', $myGameIds->merge($theirGameIds)->unique())
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        return response()->json(['data' => [
            'friend' => new FriendResource($friendUser),
            'shared' => GameResource::collection($gamesById->only($sharedIds->all())->values()),
            'mine_only' => GameResource::collection($gamesById->only($mineOnlyIds->all())->values()),
            'theirs_only' => GameResource::collection($gamesById->only($theirsOnlyIds->all())->values()),
        ]]);
    }
}
