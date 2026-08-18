<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Temporary, one-off: Render's Shell/One-Off Jobs are a paid feature, so
 * this is the only free way to copy Spanish translations already sitting
 * in a local dev database into production without re-spending DeepL quota
 * re-translating the same games. Meant to be called once and removed -
 * not a permanent admin surface.
 */
class GameTranslationBackfillController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'games' => ['required', 'array'],
            'games.*.bgg_id' => ['required', 'integer'],
            'games.*.description_es' => ['required', 'string'],
        ]);

        $updated = 0;

        foreach ($validated['games'] as $entry) {
            $updated += Game::where('bgg_id', $entry['bgg_id'])
                ->whereNull('description_es')
                ->update(['description_es' => $entry['description_es']]);
        }

        return response()->json(['updated' => $updated]);
    }
}
