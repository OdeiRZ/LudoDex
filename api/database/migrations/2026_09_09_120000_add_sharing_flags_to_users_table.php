<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // On by default, unlike discoverable - these gate what an
            // ALREADY accepted friend can see (FriendCollectionController,
            // FriendPlayController), not whether a stranger can find/request
            // you at all. Defaulting true preserves the behaviour every
            // existing accepted friendship already has in production; a
            // user opts OUT per data type instead of every friend suddenly
            // losing access the day this ships.
            $table->boolean('share_collection')->default(true)->after('discoverable');
            $table->boolean('share_plays')->default(true)->after('share_collection');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['share_collection', 'share_plays']);
        });
    }
};
