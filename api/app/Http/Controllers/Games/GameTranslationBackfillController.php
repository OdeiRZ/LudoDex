<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kept permanently (originally a one-off, since Render's Shell/One-Off
 * Jobs are a paid feature this is the only free way to copy Spanish
 * translations already sitting in a local dev database into production
 * without re-spending DeepL quota re-translating the same games) - now
 * the standing counterpart `php artisan translations:sync` calls on
 * every run, not something meant to be deleted after a single use.
 *
 * Writes to the shared games catalog rather than anything scoped to the
 * caller's own data, so plain auth:sanctum isn't enough on its own - any
 * authenticated account (there's more than one on this deployment) could
 * otherwise overwrite other games' translations. Restricted to the one
 * account configured as the app's owner (see config('app.owner_email')).
 */
class GameTranslationBackfillController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->email === config('app.owner_email'),
            403,
        );

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
