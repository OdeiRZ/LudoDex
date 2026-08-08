<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Optional link to the user's own BGG account: lets the profile
            // pull an avatar from BGG automatically (see ProfileController)
            // without forcing every user to have imported a collection first.
            $table->string('bgg_username')->nullable()->after('email');
            $table->string('avatar_url')->nullable()->after('bgg_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bgg_username', 'avatar_url']);
        });
    }
};
