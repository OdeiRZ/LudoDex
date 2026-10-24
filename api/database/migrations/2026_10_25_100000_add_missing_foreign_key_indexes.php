<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL no crea índice para una clave foránea (MySQL sí), y estas columnas
 * no encabezaban ningún índice: cada consulta por ellas recorría la tabla entera.
 * Las que más se notan: las solicitudes de amistad recibidas (friendships.recipient_id,
 * se consultan en cada navegación para el aviso del menú), quién te ha bloqueado,
 * las expansiones de un juego y los filtros por mecánica o categoría. Además, borrar
 * un juego o un usuario ya no recorre esas tablas para comprobar sus claves.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const INDICES = [
        'games' => ['base_game_id'],
        'game_mechanic' => ['mechanic_id'],
        'category_game' => ['category_id'],
        'user_games' => ['game_id'],
        'bgg_imports' => ['user_id'],
        'plays' => ['game_id'],
        'friendships' => ['recipient_id'],
        'user_blocks' => ['blocked_id'],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $columnas) {
            Schema::table($tabla, function (Blueprint $table) use ($columnas) {
                foreach ($columnas as $columna) {
                    $table->index($columna);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $columnas) {
            Schema::table($tabla, function (Blueprint $table) use ($columnas) {
                foreach ($columnas as $columna) {
                    $table->dropIndex([$columna]);
                }
            });
        }
    }
};
