<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('description')->nullable()->after('image_url');

            // Populated separately from a translation step, not by the BGG
            // import itself - games is a catalog shared across every user's
            // collection, so translating once per game (whenever that ends
            // up happening) rather than once per import avoids redoing the
            // same work for every user who happens to own the same game.
            $table->text('description_es')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['description', 'description_es']);
        });
    }
};
