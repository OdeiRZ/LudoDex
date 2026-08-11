<?php

namespace App\Http\Controllers\Bgg;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bgg\StoreBggCsvImportRequest;
use App\Services\Bgg\BggCsvImportService;
use Illuminate\Http\JsonResponse;

class BggCsvImportController extends Controller
{
    public function __construct(private readonly BggCsvImportService $importService) {}

    public function store(StoreBggCsvImportRequest $request): JsonResponse
    {
        $result = $this->importService->import($request->file('file'), $request->user());

        return response()->json(['data' => $result]);
    }
}
