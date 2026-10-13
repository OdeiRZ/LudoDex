<?php

namespace App\Services\Bootstrap;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Huella de todo lo que devuelve GET /api/bootstrap, calculada con UNA sola
 * consulta de agregados (subconsultas escalares): cuántas filas hay y cuál es
 * la última modificación de cada tabla que alimenta una sección. Si nada ha
 * cambiado, la huella es la misma y el endpoint contesta 304 sin construir
 * ni enviar la colección entera (cientos de KB).
 *
 * Qué cambia la huella, sección por sección:
 *  - usuario: users.updated_at;
 *  - colección: nº y última modificación de sus user_games y de sus games
 *    (la edición de mecánicas/categorías "toca" el juego, ver
 *    GameTaxonomySyncer) y nº de filas pivote (la importación masiva de BGG
 *    inserta pivotes directamente, sin tocar el juego);
 *  - catálogos de mecánicas y categorías: nº y última modificación;
 *  - amigos, solicitudes y bloqueos: nº y última modificación de sus filas,
 *    y la última modificación de los usuarios implicados (cambian de nombre
 *    o de avatar).
 */
class BootstrapVersion
{
    /** Súbelo si cambia la forma de la respuesta: invalida las huellas ya guardadas en los móviles. */
    public const FORMAT_VERSION = 1;

    public static function etagFor(User $user): string
    {
        // Entero ya convertido: se interpola porque PDO no admite repetir un
        // parámetro con nombre en todos los motores y así evitamos 13 enlaces.
        $id = (int) $user->id;

        $row = DB::selectOne(<<<SQL
            select
                (select count(*) from user_games ug where ug.user_id = {$id}) as games_n,
                (select max(ug.updated_at) from user_games ug where ug.user_id = {$id}) as games_ug,
                (select max(g.updated_at) from games g join user_games ug on ug.game_id = g.id where ug.user_id = {$id}) as games_g,
                (select count(*) from game_mechanic gm join user_games ug on ug.game_id = gm.game_id where ug.user_id = {$id}) as pivot_m,
                (select count(*) from category_game cg join user_games ug on ug.game_id = cg.game_id where ug.user_id = {$id}) as pivot_c,
                (select count(*) from mechanics) as mech_n,
                (select max(updated_at) from mechanics) as mech_m,
                (select count(*) from categories) as cat_n,
                (select max(updated_at) from categories) as cat_m,
                (select count(*) from friendships f where f.requester_id = {$id} or f.recipient_id = {$id}) as fr_n,
                (select max(f.updated_at) from friendships f where f.requester_id = {$id} or f.recipient_id = {$id}) as fr_m,
                (select count(*) from user_blocks b where b.blocker_id = {$id}) as bl_n,
                (select max(b.updated_at) from user_blocks b where b.blocker_id = {$id}) as bl_m,
                (select max(x.updated_at) from users x where
                    x.id in (select f.recipient_id from friendships f where f.requester_id = {$id})
                    or x.id in (select f.requester_id from friendships f where f.recipient_id = {$id})
                    or x.id in (select b.blocked_id from user_blocks b where b.blocker_id = {$id})
                ) as related_m
        SQL);

        return '"'.md5((string) json_encode([
            self::FORMAT_VERSION,
            $user->updated_at?->toIso8601String(),
            $row,
        ])).'"';
    }

    /** ¿El If-None-Match del cliente coincide con la huella actual? (admite varias y el prefijo débil W/). */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (preg_replace('/^\s*W\//', '', trim($candidate)) === $etag) {
                return true;
            }
        }

        return false;
    }
}
