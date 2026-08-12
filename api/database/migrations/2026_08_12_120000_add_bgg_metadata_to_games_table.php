<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedSmallInteger('year_published')->nullable()->after('image_url');

            // BGG's own recommended age range as free text ("10+", "4-12"),
            // not a single number - deliberately not parsed into a plain
            // integer, since BGG doesn't always report a clean minimum.
            $table->string('min_age', 20)->nullable()->after('year_published');

            // BGG's overall rank for the game's type (e.g. "board game
            // rank"). Null means unranked, not "unknown" - BGG reports 0 for
            // games with too few ratings to rank, and the CSV importer
            // treats that the same way.
            $table->unsignedInteger('bgg_rank')->nullable()->after('min_age');

            // BGG's "average" (the plain arithmetic mean of user ratings),
            // not "bayesaverage" (the Bayesian-adjusted "Geek Rating" BGG
            // itself uses for bgg_rank) - chosen deliberately even though
            // it skews higher for lightly-rated games, since it's the
            // number BGG actually displays most prominently.
            $table->decimal('rating', 4, 2)->nullable()->after('bgg_rank');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['year_published', 'min_age', 'bgg_rank', 'rating']);
        });
    }
};
