<?php

namespace App\Http\Controllers\Bgg;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bgg\StoreBggImportRequest;
use App\Http\Resources\BggImportResource;
use App\Models\BggImport;
use App\Services\Bgg\BggImportService;
use Illuminate\Http\Request;

class BggImportController extends Controller
{
    public function __construct(private readonly BggImportService $importService) {}

    public function store(StoreBggImportRequest $request): BggImportResource
    {
        $import = $request->user()->bggImports()->create([
            'bgg_username' => $request->validated('bgg_username'),
            'status' => 'pending',
        ]);

        $this->importService->attempt($import);

        return new BggImportResource($import);
    }

    public function show(Request $request, BggImport $bggImport): BggImportResource
    {
        $this->authorize('view', $bggImport);

        // Polling endpoint: BGG's own export is asynchronous (202 while it
        // prepares), so each poll re-attempts rather than just reading
        // stored state - see BggImportService::attempt().
        $this->importService->attempt($bggImport);

        return new BggImportResource($bggImport);
    }
}
