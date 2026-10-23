<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;

/**
 * La colección ("lo tengo") de un usuario vista a través de su enlace público: sin
 * sesión, solo lectura. Es la ÚNICA ruta de la API que responde sin autenticación
 * con datos de un usuario, así que todo lo que sale de aquí es lista blanca:
 *
 *  - el nombre del dueño (el usuario decidió mostrarlo al compartir),
 *  - los juegos con los datos del catálogo compartido (GameResource, los mismos que
 *    ya ven sus amigos), solo con estado "owned": la lista de deseos NO se enseña,
 *  - la fecha de caducidad.
 *
 * Nunca el correo, el id del usuario, sus partidas, sus amigos ni sus ajustes.
 * Un token mal formado, inexistente, revocado o caducado responde exactamente igual
 * (404): no se puede averiguar si un enlace existió.
 */
class PublicCollectionController extends Controller
{
    public function show(string $token): JsonResponse
    {
        abort_unless(ShareLink::isWellFormedToken($token), 404);

        $link = ShareLink::query()->active()->where('token', $token)->with('user')->first();
        abort_if($link === null, 404);

        $gameIds = $link->user->games()->where('status', 'owned')->pluck('game_id');

        $games = Game::with(['mechanics', 'categories', 'baseGame'])
            ->whereIn('id', $gameIds)
            ->orderBy('name')
            ->get();

        $link->forceFill([
            'views_count' => $link->views_count + 1,
            'last_viewed_at' => now(),
        ])->saveQuietly();

        return response()->json(['data' => [
            'owner_name' => $link->user->name,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'games' => GameResource::collection($games),
        ]])->withHeaders([
            // Un enlace es privado: que ningún buscador lo indexe ni lo guarde en caché
            // compartida (el contador de visitas y la caducidad no deben quedar congelados).
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Lo mínimo para la vista previa de un enlace en chats (WhatsApp, Telegram...): nombre
     * del dueño y cuántos juegos tiene. Lo consulta la función de Cloudflare cuando un bot
     * pide la página, así que NO cuenta como visita (si no, cada vez que alguien pega el
     * enlace en un chat subiría el contador). Mismo 404 uniforme que show().
     */
    public function preview(string $token): JsonResponse
    {
        abort_unless(ShareLink::isWellFormedToken($token), 404);

        $link = ShareLink::query()->active()->where('token', $token)->with('user')->first();
        abort_if($link === null, 404);

        return response()->json(['data' => [
            'owner_name' => $link->user->name,
            'games_count' => $link->user->games()->where('status', 'owned')->count(),
        ]])->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store',
        ]);
    }
}
