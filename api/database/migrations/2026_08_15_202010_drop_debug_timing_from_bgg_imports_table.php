<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Served its purpose: pinpointed the BGG import's DB-write phase as the
// real bottleneck (133s of a ~137s import), since Render wasn't
// surfacing Log:: output from the php artisan serve process in
// production. No longer needed now that the write phase is batched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            $table->dropColumn('debug_timing');
        });
    }

    public function down(): void
    {
        Schema::table('bgg_imports', function (Blueprint $table) {
            $table->text('debug_timing')->nullable();
        });
    }
};
