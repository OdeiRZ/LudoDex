<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_mechanic', function (Blueprint $table) {
            $table->foreignUlid('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->primary(['game_id', 'mechanic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_mechanic');
    }
};
