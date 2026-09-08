<?php

use App\Models\Friendship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friendships', function (Blueprint $table) {
            // Direction-independent key for a pair - "min(a,b)_max(a,b)",
            // the same value regardless of who's requester/recipient.
            // Nullable at the DB level (both SQLite and Postgres allow
            // multiple NULLs through a unique index) but always set by
            // FriendshipService::sendRequest() for every new row - see
            // that class for why this exists: a unique index is the only
            // thing that actually stops two concurrent requests (from
            // separate processes, not just separate PHP calls in the same
            // one) from ever creating two mutual pending rows for the
            // same pair, one in each direction. The SELECT-then-INSERT
            // checks already in that service only protect against
            // sequential requests.
            $table->string('pair_key')->nullable()->after('recipient_id');
        });

        // Backfilled in PHP rather than a raw LEAST()/GREATEST() SQL
        // expression - keeps this portable across the sqlite (tests) and
        // postgres (production) drivers this app actually runs on,
        // without needing two different SQL dialects here.
        Friendship::query()->each(function (Friendship $friendship) {
            $friendship->update([
                'pair_key' => Friendship::pairKey($friendship->requester_id, $friendship->recipient_id),
            ]);
        });

        Schema::table('friendships', function (Blueprint $table) {
            $table->unique('pair_key');
        });
    }

    public function down(): void
    {
        Schema::table('friendships', function (Blueprint $table) {
            $table->dropUnique(['pair_key']);
            $table->dropColumn('pair_key');
        });
    }
};
