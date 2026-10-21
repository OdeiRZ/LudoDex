<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Gestión del enlace público de la propia colección (el que ve cualquiera sin
 * cuenta lo sirve PublicCollectionController). Un solo enlace por usuario: se crea,
 * se regenera (el anterior deja de valer) o se borra.
 */
class ShareLinkController extends Controller
{
    /** Caducidades que ofrece la pantalla; `null` = no caduca. */
    public const ALLOWED_DAYS = [7, 30, 90];

    public const DEFAULT_DAYS = 7;

    public function show(Request $request): JsonResponse
    {
        $link = $request->user()->shareLink;

        return response()->json(['data' => $link === null ? null : $this->present($link)]);
    }

    /**
     * Crea el enlace o, si ya había uno, lo REGENERA: token nuevo y caducidad nueva,
     * contador a cero. El token anterior deja de valer en el acto.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Ausente = la caducidad por defecto (7 días); null explícito = no caduca.
            'expires_in_days' => ['sometimes', 'nullable', 'integer', Rule::in(self::ALLOWED_DAYS)],
        ]);

        $days = array_key_exists('expires_in_days', $validated) ? $validated['expires_in_days'] : self::DEFAULT_DAYS;

        $link = $request->user()->shareLink()->updateOrCreate([], [
            'token' => ShareLink::generateToken(),
            'expires_at' => $days === null ? null : now()->addDays($days),
            'views_count' => 0,
            'last_viewed_at' => null,
        ]);

        return response()->json(['data' => $this->present($link)], 201);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->shareLink()->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShareLink $link): array
    {
        return [
            'token' => $link->token,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'expired' => $link->isExpired(),
            'views_count' => $link->views_count,
            'last_viewed_at' => $link->last_viewed_at?->toIso8601String(),
        ];
    }
}
