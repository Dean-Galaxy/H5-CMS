<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_pixels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id')->index();
            $t->string('name', 100);
            $t->string('platform', 12);
            $t->string('external_id', 64);
            $t->string('environment', 12);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->string('local_status', 40)->default('not_tested');
            $t->timestampsTz();
            $t->unique(['platform', 'external_id']);
        });
        Schema::create('acquisition_pixel_versions', function (Blueprint $t) {
            $t->uuid('pixel_id');
            $t->unsignedInteger('version');
            $t->string('platform', 12);
            $t->string('external_id', 64);
            $t->string('environment', 12);
            $t->timestampTz('created_at');
            $t->primary(['pixel_id', 'version']);
        });
        Schema::create('acquisition_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id')->index();
            $t->string('name', 100);
            $t->string('code', 48)->unique();
            $t->uuid('drama_id');
            $t->string('drama_title', 255);
            $t->string('platform', 12);
            $t->uuid('pixel_id')->index();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('acquisition_assignments', function (Blueprint $t) {
            $t->string('resource_type', 10);
            $t->uuid('resource_id');
            $t->uuid('staff_id');
            $t->timestampTz('created_at');
            $t->primary(['resource_type', 'resource_id', 'staff_id']);
            $t->index('staff_id');
        });
        // Only 05 owns these immutable source records. No browser-provided snapshot is trusted.
        Schema::create('acquisition_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('link_id')->index();
            $t->uuid('pixel_id');
            $t->unsignedInteger('pixel_version');
            $t->string('channel', 12);
            $t->string('consent', 16);
            $t->string('policy_version', 80);
            $t->timestampTz('captured_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->text('context_encrypted')->nullable();
            $t->boolean('is_simulation')->default(false);
        });
        Schema::create('acquisition_receipts', function (Blueprint $t) {
            $t->uuid('event_id')->primary();
            $t->uuid('payment_id')->unique();
            $t->string('fingerprint', 64);
            $t->uuid('link_id')->nullable()->index();
            $t->uuid('source_id')->nullable();
            $t->string('transaction_kind', 30);
            $t->boolean('discount_applied');
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->boolean('is_test');
            $t->timestampTz('occurred_at');
            $t->jsonb('attribution_snapshot')->nullable();
            $t->string('source_status', 40);
            $t->timestampTz('received_at');
        });
        Schema::create('acquisition_event_ids', function (Blueprint $t) {
            $t->uuid('event_id')->primary();
            $t->uuid('payment_id')->index();
        });
        Schema::create('acquisition_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('receipt_id')->unique();
            $t->uuid('link_id')->index();
            $t->uuid('source_id');
            $t->uuid('pixel_id')->index();
            $t->unsignedInteger('pixel_version');
            $t->string('platform', 12);
            $t->string('external_id', 64);
            $t->string('environment', 12);
            $t->string('event_key', 80)->unique();
            $t->string('state', 32)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 80)->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('acquisition_simulations', function (Blueprint $t) {
            $t->string('event_key', 80)->primary();
            $t->string('platform', 12);
            $t->string('payload_digest', 64);
            $t->unsignedInteger('calls')->default(1);
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['simulations', 'deliveries', 'event_ids', 'receipts', 'sources', 'assignments', 'links', 'pixel_versions', 'pixels'] as $name) {
            Schema::dropIfExists('acquisition_'.$name);
        }
    }
};
