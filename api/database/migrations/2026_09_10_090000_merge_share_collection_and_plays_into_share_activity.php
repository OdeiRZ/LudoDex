<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * share_collection and share_plays (added 2026_09_09_120000) turn out to be
 * more precision than this app needs - asked for directly: an already-
 * accepted friend either sees your activity or doesn't, no real case for
 * hiding just one of collection/plays from someone you've already accepted.
 * Collapsed into a single share_activity flag, used everywhere both used
 * to be checked (FriendCollectionController, FriendPlayController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('share_activity')->default(true)->after('discoverable');
        });

        // Chunked PHP AND, not a raw SQL boolean AND - keeps this portable
        // between SQLite (tests) and Postgres (production) without relying
        // on either engine's own boolean-column semantics. Whichever of
        // the two was already false wins - a user who'd specifically
        // turned off sharing one data type gets the more private outcome
        // under the merged flag, not silently re-exposed.
        DB::table('users')->orderBy('id')->select(['id', 'share_collection', 'share_plays'])
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'share_activity' => $user->share_collection && $user->share_plays,
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['share_collection', 'share_plays']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('share_collection')->default(true)->after('discoverable');
            $table->boolean('share_plays')->default(true)->after('share_collection');
        });

        DB::table('users')->orderBy('id')->select(['id', 'share_activity'])
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'share_collection' => $user->share_activity,
                        'share_plays' => $user->share_activity,
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('share_activity');
        });
    }
};
