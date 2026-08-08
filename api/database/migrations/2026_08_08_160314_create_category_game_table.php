<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named category_game (not game_category) to match Eloquent's
        // default belongsToMany pivot naming convention (alphabetical order
        // of the two model names).
        Schema::create('category_game', function (Blueprint $table) {
            $table->foreignUlid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['game_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_game');
    }
};
