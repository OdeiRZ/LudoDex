<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();

            // One-directional, unlike friendships - blocker_id/blocked_id
            // are never read symmetrically. A block can exist without any
            // friendships row ever having existed between the two (e.g.
            // blocking someone straight from an unwanted incoming request).
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
    }
};
