<?php

namespace App\Http\Controllers\Friends;

use App\Http\Controllers\Controller;
use App\Http\Requests\Friends\StoreBlockRequest;
use App\Http\Resources\FriendResource;
use App\Models\UserBlock;
use App\Services\Friends\BlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlockController extends Controller
{
    public function __construct(private readonly BlockService $blockService) {}

    public function index(Request $request): JsonResponse
    {
        $blocks = $this->blockService->blockedFor($request->user());

        return response()->json(['data' => $blocks->map(fn (UserBlock $block) => [
            'id' => $block->id,
            'user' => new FriendResource($block->blocked),
        ])]);
    }

    public function store(StoreBlockRequest $request): JsonResponse
    {
        $block = $this->blockService->block($request->user(), $request->validated('user_id'));

        return response()->json(['data' => ['id' => $block->id]], 201);
    }

    public function destroy(Request $request, UserBlock $block): Response
    {
        $this->authorize('delete', $block);

        $block->delete();

        return response()->noContent();
    }
}
