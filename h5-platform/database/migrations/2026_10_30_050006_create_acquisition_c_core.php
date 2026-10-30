<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_c_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('choice', 8)->default('unset');
            $t->unsignedInteger('revision')->default(0);
            $t->timestampTz('created_at', 6);
            $t->timestampTz('updated_at', 6);
            $t->timestampTz('expires_at', 6)->index();
        });
        Schema::create('acquisition_c_subjects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('preference_id')->constrained('acquisition_c_preferences')->cascadeOnDelete();
            $t->string('policy_version', 80);
            $t->string('notice_version', 80);
            $t->boolean('is_test');
            $t->timestampTz('created_at', 6);
            $t->timestampTz('expires_at', 6)->index();
            $t->timestampTz('ended_at', 6)->nullable();
        });
        Schema::create('acquisition_c_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subject_id')->constrained('acquisition_c_subjects')->cascadeOnDelete();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('last_seen_at', 6);
            $t->timestampTz('expires_at', 6)->index();
            $t->timestampTz('ended_at', 6)->nullable();
        });
        Schema::create('acquisition_c_events', function (Blueprint $t) {
            $t->bigIncrements('sequence');
            $t->uuid('id')->unique();
            $t->char('dedup_key', 64)->unique();
            $t->char('fingerprint', 64);
            $t->foreignUuid('subject_id')->constrained('acquisition_c_subjects')->cascadeOnDelete();
            $t->foreignUuid('measurement_session_id')->constrained('acquisition_c_sessions')->cascadeOnDelete();
            $t->string('event_name', 40);
            $t->unsignedSmallInteger('schema_version')->default(2);
            $t->string('basis_kind', 24)->default('policy_permitted');
            $t->string('policy_version', 80);
            $t->string('notice_version', 80);
            $t->boolean('is_test');
            $t->jsonb('properties');
            $t->timestampTz('received_at', 6)->index();
            $t->index(['subject_id', 'sequence']);
        });
        Schema::create('acquisition_c_event_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subject_id')->constrained('acquisition_c_subjects')->cascadeOnDelete();
            $t->char('fingerprint', 64);
            $t->timestampTz('created_at', 6)->index();
        });
        Schema::create('acquisition_c_plays', function (Blueprint $t) {
            $t->foreignUuid('subject_id')->constrained('acquisition_c_subjects')->cascadeOnDelete();
            $t->uuid('play_instance_id');
            $t->foreignUuid('measurement_session_id')->constrained('acquisition_c_sessions')->cascadeOnDelete();
            $t->uuid('episode_id');
            $t->boolean('started')->default(false);
            $t->double('watched_seconds')->default(0);
            $t->timestampTz('created_at', 6);
            $t->timestampTz('updated_at', 6)->index();
            $t->primary(['subject_id', 'play_instance_id']);
        });
    }

    public function down(): void
    {
        foreach (['plays', 'event_keys', 'events', 'sessions', 'subjects', 'preferences'] as $name) {
            Schema::dropIfExists('acquisition_c_'.$name);
        }
    }
};
