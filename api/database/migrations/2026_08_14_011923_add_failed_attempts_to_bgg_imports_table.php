<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            // Consecutive transient-failure count while talking to BGG.
            // Reset to 0 on any successful response; only after this hits
            // BggImportService::MAX_CONSECUTIVE_FAILURES does status flip
            // to a permanent 'failed', instead of on the first hiccup.
            $table->unsignedTinyInteger('failed_attempts')->default(0)->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            $table->dropColumn('failed_attempts');
        });
    }
};
