<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other modules' IDs are opaque references, never cross-module foreign keys.
        Schema::create('audience_playback_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('viewer_id');
            $table->uuid('drama_id');
            $table->uuid('episode_id');
            $table->uuid('play_session_id')->nullable()->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['viewer_id', 'drama_id']);
        });
        Schema::create('audience_progress', function (Blueprint $table) {
            $table->uuid('viewer_id');
            $table->uuid('drama_id');
            $table->uuid('episode_id')->nullable();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->uuid('play_session_id')->nullable();
            $table->unsignedBigInteger('session_generation')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->timestampTz('saved_at')->nullable();
            $table->primary(['viewer_id', 'drama_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_progress');
        Schema::dropIfExists('audience_playback_sessions');
    }
};
