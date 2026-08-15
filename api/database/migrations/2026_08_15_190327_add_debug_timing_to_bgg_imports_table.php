<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Temporary: Render's log pipeline isn't surfacing Log:: output from the
// php artisan serve process this app runs under in production (neither a
// LOG_CHANNEL nor a LOG_LEVEL change made the per-phase import-timing logs
// appear), so the same numbers are persisted here instead, readable
// directly via the Neon SQL console. Dropped once the real bottleneck in
// a real BGG import (consistently ~2m across several live attempts,
// regardless of a warm /thing cache) is found.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            $table->text('debug_timing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            $table->dropColumn('debug_timing');
        });
    }
};
