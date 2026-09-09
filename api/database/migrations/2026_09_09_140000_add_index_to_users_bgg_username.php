<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Hallazgo de auditoria: a diferencia de email (unique desde el
            // origen), bgg_username no llevaba indice pese a usarse como
            // predicado de busqueda en FriendshipService::search() - cada
            // busqueda por usuario de BGG era un full scan. No unique (a
            // diferencia de email): nada impide que dos cuentas compartan
            // el mismo bgg_username, ya sea porque una lo copio sin ser
            // suyo o porque el otro dueno aun no se ha registrado aqui.
            $table->index('bgg_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['bgg_username']);
        });
    }
};
