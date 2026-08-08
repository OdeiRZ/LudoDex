<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Set when imported from BoardGameGeek (milestone 3); null for
            // games added by hand. Unique so re-importing the same BGG game
            // updates the existing row instead of duplicating it.
            $table->unsignedInteger('bgg_id')->nullable()->unique();

            $table->string('name');
            $table->string('image_url')->nullable();
            $table->unsignedSmallInteger('min_players')->nullable();
            $table->unsignedSmallInteger('max_players')->nullable();
            $table->unsignedSmallInteger('min_playtime_minutes')->nullable();
            $table->unsignedSmallInteger('max_playtime_minutes')->nullable();
            // BGG's "weight" (complexity), 1.0-5.0.
            $table->decimal('weight', 3, 2)->nullable();

            // Not a strict either/or: a game can be neither, or both (e.g.
            // team-based games are competitive between teams and cooperative
            // within one). Sourced from BGG categories/mechanics when
            // imported, but always overridable by hand since that mapping is
            // an inference, not a BGG field.
            $table->boolean('is_cooperative')->default(false);
            $table->boolean('is_competitive')->default(false);
            $table->boolean('has_campaign')->default(false);

            // Expansions aren't standalone games - they extend a base game
            // (different player/time counts, no playable rules of their
            // own). Self-referencing instead of a separate table so the
            // picker can treat "base + owned expansions" as one unit.
            $table->foreignUlid('base_game_id')->nullable()->constrained('games')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
