<?php

namespace App\Http\Controllers\Bgg;

use App\Http\Controllers\Controller;
use App\Services\Bgg\BggClient;
use Illuminate\Http\JsonResponse;

class BggLookupController extends Controller
{
    public function __construct(private readonly BggClient $client) {}

    public function show(int $bggId): JsonResponse
    {
        $result = $this->client->fetchGameByBggId($bggId);

        if ($result['status'] === 'error') {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['data' => $result['game']]);
    }
}
