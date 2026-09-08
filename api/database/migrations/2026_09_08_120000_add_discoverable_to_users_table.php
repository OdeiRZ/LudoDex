<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Off by default on purpose - a user has to opt in before anyone
            // can find them by email/BGG username search (see
            // FriendshipService::search()). Also gates being a valid target
            // for a friend request at all, not just search visibility - see
            // FriendshipService::sendRequest().
            $table->boolean('discoverable')->default(false)->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discoverable');
        });
    }
};
