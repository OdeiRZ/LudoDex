<?php

namespace App\Http\Controllers\Bgg;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bgg\StorePlaysImportRequest;
use App\Services\Bgg\BggPlaysImportService;
use Illuminate\Http\JsonResponse;

class BggPlaysImportController extends Controller
{
    public function __construct(private readonly BggPlaysImportService $importService) {}

    public function store(StorePlaysImportRequest $request): JsonResponse
    {
        $result = $this->importService->import(
            $request->validated('bgg_username'),
            $request->user(),
            $request->boolean('full'),
        );

        if ($result['status'] === 'error') {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['data' => ['imported_count' => $result['imported_count']]]);
    }
}
