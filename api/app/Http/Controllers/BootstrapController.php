<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Friends\BlockController;
use App\Http\Controllers\Friends\FriendshipController;
use App\Http\Controllers\Games\CategoryController;
use App\Http\Controllers\Games\MechanicController;
use App\Http\Controllers\Games\UserGameController;
use App\Services\Bootstrap\BootstrapVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Todo lo que la app pide al abrirse, en UNA petición: usuario, colección,
 * catálogos de mecánicas y categorías, amigos, solicitudes y bloqueos.
 *
 * Antes eran 8 peticiones simultáneas (con `friends/requests` repetida) contra
 * un servidor de 4 trabajadores y 3 consultas de autenticación cada una: medido
 * en producción, sueltas tardaban ~450 ms y juntas 1,5-2 s, y la lista de
 * juegos no terminaba hasta +3 s. Cada sección sale de EXACTAMENTE el mismo
 * controlador que su endpoint de siempre (que sigue existiendo, para los
 * refrescos puntuales y como respaldo), así que no hay lógica duplicada.
 *
 * Es condicional (ETag): el cliente guarda la última respuesta con su huella y
 * la envía en If-None-Match; si no ha cambiado nada, 304 sin cuerpo.
 */
class BootstrapController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $etag = BootstrapVersion::etagFor($user);

        if (BootstrapVersion::matches($request->header('If-None-Match'), $etag)) {
            return response()->json(status: 304)->header('ETag', $etag);
        }

        $requests = app(FriendshipController::class)->requests($request)->getData(true)['data'];

        return response()->json([
            'user' => response()->json($user)->getData(true),
            'games' => $this->listOf(app(UserGameController::class)->index($request), $request),
            'mechanics' => $this->listOf(app(MechanicController::class)->index(), $request),
            'categories' => $this->listOf(app(CategoryController::class)->index(), $request),
            'friends' => app(FriendshipController::class)->index($request)->getData(true)['data'],
            'requests' => $requests,
            'blocks' => app(BlockController::class)->index($request)->getData(true)['data'],
        ])->header('ETag', $etag);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(AnonymousResourceCollection $collection, Request $request): array
    {
        return $collection->response($request)->getData(true)['data'];
    }
}
