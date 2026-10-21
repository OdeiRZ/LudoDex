<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();

            // Un enlace por usuario (se crea, se regenera o se borra, nunca se
            // acumulan): `unique` lo garantiza aunque dos peticiones lleguen a la vez.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Aleatorio de 256 bits (ver ShareLink::generateToken), sin relación con
            // ningún id ni dato del usuario. Se guarda tal cual, no con hash: la
            // pantalla de Perfil tiene que poder volver a mostrar el enlace entero, y
            // quien pudiera leer la base de datos ya vería la colección sin el token.
            $table->string('token', 64)->unique();

            // null = no caduca. Pasada esa fecha el enlace responde 404, igual que uno
            // inexistente: nadie distingue "caducado" de "nunca existió".
            $table->timestamp('expires_at')->nullable();

            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
