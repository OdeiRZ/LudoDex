<?php

namespace App\Http\Controllers\Friends;

use App\Http\Controllers\Controller;
use App\Http\Requests\Friends\SearchFriendRequest;
use App\Http\Requests\Friends\StoreFriendRequestRequest;
use App\Http\Resources\FriendResource;
use App\Models\Friendship;
use App\Services\Friends\FriendshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FriendshipController extends Controller
{
    public function __construct(private readonly FriendshipService $friendshipService) {}

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $friendships = $this->friendshipService->friendsFor($me);

        return response()->json(['data' => $friendships->map(fn (Friendship $friendship) => [
            'id' => $friendship->id,
            'user' => new FriendResource($friendship->otherUserFor($me)),
        ])]);
    }

    public function search(SearchFriendRequest $request): JsonResponse
    {
        $result = $this->friendshipService->search(
            $request->user(),
            $request->validated('email'),
            $request->validated('bgg_username'),
        );

        return response()->json(['data' => $result ? new FriendResource($result) : null]);
    }

    /**
     * Both directions in one response - a friends-management page needs
     * both lists at once, and this keeps it to a single round trip instead
     * of two.
     */
    public function requests(Request $request): JsonResponse
    {
        $me = $request->user();

        $incoming = Friendship::pending()
            ->where('recipient_id', $me->id)
            ->with('requester')
            ->get();

        $outgoing = Friendship::pending()
            ->where('requester_id', $me->id)
            ->with('recipient')
            ->get();

        return response()->json(['data' => [
            'incoming' => $incoming->map(fn (Friendship $friendship) => [
                'id' => $friendship->id,
                'user' => new FriendResource($friendship->requester),
            ]),
            'outgoing' => $outgoing->map(fn (Friendship $friendship) => [
                'id' => $friendship->id,
                'user' => new FriendResource($friendship->recipient),
            ]),
        ]]);
    }

    public function store(StoreFriendRequestRequest $request): JsonResponse
    {
        $friendship = $this->friendshipService->sendRequest(
            $request->user(),
            $request->validated('user_id'),
        );

        return response()->json(['data' => [
            'id' => $friendship->id,
            'status' => $friendship->status,
        ]], 201);
    }

    public function accept(Request $request, Friendship $friendship): JsonResponse
    {
        $this->authorize('accept', $friendship);

        $friendship->update(['status' => 'accepted']);

        return response()->json(['data' => ['id' => $friendship->id, 'status' => $friendship->status]]);
    }

    public function destroy(Friendship $friendship): Response
    {
        $this->authorize('delete', $friendship);

        $friendship->delete();

        return response()->noContent();
    }
}
