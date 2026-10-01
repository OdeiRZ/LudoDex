<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // On by default - a short sound/vibration on key interactions
            // (buttons, dice-roll toast) is the default experience, opt-out
            // for anyone who'd rather have the app stay silent.
            $table->boolean('interaction_feedback_enabled')->default(true)->after('share_activity');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('interaction_feedback_enabled');
        });
    }
};
