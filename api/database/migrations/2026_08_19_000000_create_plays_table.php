<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('game_id')->constrained()->cascadeOnDelete();

            // BGG's own play id - unique per user so a reimport upserts
            // existing rows instead of duplicating every play each time.
            $table->unsignedInteger('bgg_play_id');

            $table->date('played_at');

            // BGG batches identical repeat-same-day plays of the same game
            // into one <play quantity="N"> entry instead of N separate ones.
            $table->unsignedSmallInteger('quantity')->default(1);

            // Minutes; null when BGG reports no length (length="0", same
            // "0 means no data" convention as the games table's own
            // duration fields).
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'bgg_play_id']);
            $table->index(['user_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plays');
    }
};
