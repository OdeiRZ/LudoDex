<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();

            // One row per pair, not two - requester_id/recipient_id record
            // who sent it (matters while status is 'pending'), but once
            // 'accepted' the relationship is read as symmetric by
            // FriendshipService::friendsFor(), which checks both columns.
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();

            // 'pending' | 'accepted' - no 'declined'/'blocked' state on
            // purpose: declining/cancelling/unfriending all just delete the
            // row (FriendshipController::destroy()), back to no relationship
            // at all, re-requestable later.
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->unique(['requester_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
