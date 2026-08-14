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
        $username = $request->validated('bgg_username');

        // A client that isn't sure whether an earlier request for the same
        // username actually reached the server (a dropped connection, a
        // backgrounded tab) retries this endpoint rather than giving up -
        // reusing a still-pending import instead of always creating a new
        // one keeps that retry idempotent, so it doesn't leave several
        // orphaned rows silently competing to import the same collection.
        $import = $request->user()->bggImports()
            ->where('status', 'pending')
            ->where('bgg_username', $username)
            ->latest()
            ->first();

        if ($import === null) {
            $import = $request->user()->bggImports()->create([
                'bgg_username' => $username,
                'status' => 'pending',
            ]);
        }

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
