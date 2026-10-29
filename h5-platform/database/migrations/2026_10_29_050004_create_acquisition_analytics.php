<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_privacy', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('current_subject')->nullable();
            $t->uuid('active_source')->nullable();
            $t->uuid('viewer_id')->nullable();
            $t->string('analytics', 12);
            $t->string('advertising', 12);
            $t->string('policy_version', 40);
            $t->timestampTz('analytics_granted_at', 6)->nullable();
            $t->timestampsTz(6);
        });
        Schema::create('acquisition_subjects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('privacy_id')->index();
            $t->uuid('viewer_id')->nullable()->index();
            $t->uuid('session_key');
            $t->timestampsTz(6);
        });
        Schema::table('acquisition_sources', function (Blueprint $t) {
            $t->timestampTz('captured_at', 6)->change();
            $t->timestampTz('expires_at', 6)->change();
            $t->uuid('privacy_id')->nullable()->index();
            $t->uuid('subject_id')->nullable()->index();
            $t->uuid('landing_key')->nullable()->unique();
        });
        Schema::create('acquisition_behaviors', function (Blueprint $t) {
            $t->bigIncrements('sequence');
            $t->uuid('id')->unique();
            $t->string('dedup_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->string('event_name', 40)->index();
            $t->string('origin', 10);
            $t->uuid('subject_id')->index();
            $t->uuid('privacy_id')->index();
            $t->uuid('session_key');
            $t->uuid('viewer_id')->nullable();
            $t->uuid('source_id')->nullable();
            $t->uuid('link_id')->nullable()->index();
            $t->uuid('drama_id')->nullable()->index();
            $t->uuid('episode_id')->nullable();
            $t->string('drama_title', 200)->nullable();
            $t->string('episode_title', 200)->nullable();
            $t->unsignedInteger('episode_number')->nullable();
            $t->string('channel', 16);
            $t->string('device', 12);
            $t->string('country', 12);
            $t->string('locale', 8);
            $t->boolean('is_test');
            $t->string('policy_version', 40);
            $t->jsonb('properties');
            $t->timestampTz('occurred_at', 6)->index();
            $t->timestampTz('ordered_at', 6)->index();
            $t->timestampTz('received_at', 6);
        });
        Schema::create('acquisition_event_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('subject_id');
            $t->string('fingerprint', 64);
            $t->timestampTz('created_at', 6)->index();
        });
        Schema::create('acquisition_play_states', function (Blueprint $t) {
            $t->uuid('subject_id');
            $t->uuid('play_instance_id');
            $t->uuid('episode_id');
            $t->unsignedInteger('watched_seconds')->default(0);
            $t->boolean('started')->default(false);
            $t->boolean('completed')->default(false);
            $t->timestampsTz(6);
            $t->primary(['subject_id', 'play_instance_id']);
        });
        Schema::create('acquisition_analytics_orders', function (Blueprint $t) {
            $t->uuid('order_id')->primary();
            $t->timestampTz('checkout_at', 6)->nullable();
            $t->uuid('subject_id');
            $t->uuid('privacy_id');
            $t->uuid('viewer_id');
            $t->uuid('source_id')->nullable();
            $t->uuid('subscription_id')->nullable()->index();
            $t->timestampTz('created_at', 6);
        });
        Schema::create('acquisition_refund_versions', function (Blueprint $t) {
            $t->uuid('payment_id')->primary();
            $t->unsignedBigInteger('fact_version');
            $t->unsignedBigInteger('refunded_amount_minor');
        });
        Schema::create('acquisition_capture_diagnostics', function (Blueprint $t) {
            $t->string('reason', 48)->primary();
            $t->unsignedBigInteger('count')->default(0);
            $t->timestampTz('last_at', 6);
        });
    }

    public function down(): void
    {
        foreach (['capture_diagnostics', 'refund_versions', 'analytics_orders', 'play_states', 'event_keys', 'behaviors', 'subjects', 'privacy'] as $name) {
            Schema::dropIfExists('acquisition_'.$name);
        }
        Schema::table('acquisition_sources', fn (Blueprint $t) => $t->dropColumn(['privacy_id', 'subject_id', 'landing_key']));
    }
};
