<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\DeepLTranslationService;
use Illuminate\Http\JsonResponse;

class GameDescriptionController extends Controller
{
    public function __construct(private readonly DeepLTranslationService $translator) {}

    /**
     * Translates a game's own description to Spanish, on demand - games is
     * a catalog shared across every user, so this only ever needs to run
     * once per game total, not once per user who happens to own it.
     * Idempotent: an already-translated game skips DeepL entirely and just
     * echoes description_es back, same response shape either way so the
     * frontend doesn't need to know which case it got.
     */
    public function translate(Game $game): JsonResponse
    {
        if ($game->description_es === null && $game->description !== null) {
            $translated = $this->translator->translateToSpanish($game->description);

            if ($translated !== null) {
                $game->update(['description_es' => $translated]);
            }
        }

        return response()->json(['description_es' => $game->description_es]);
    }
}
