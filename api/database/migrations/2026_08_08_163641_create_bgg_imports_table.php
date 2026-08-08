<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgg_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bgg_username');
            // No background worker (see README) - this row is polled and
            // re-attempted on each GET until BoardGameGeek's own export
            // (itself asynchronous, HTTP 202 while it prepares) is ready.
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('imported_count')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgg_imports');
    }
};
