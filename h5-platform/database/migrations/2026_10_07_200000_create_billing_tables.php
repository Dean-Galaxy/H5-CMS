<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $t) {
            $t->uuid('viewer_id')->primary();
            $t->timestampTz('first_paid_at')->nullable();
            $t->timestampTz('first_offer_used_at')->nullable();
        });
        Schema::create('billing_prices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->unsignedInteger('regular_amount_minor');
            $t->unsignedInteger('first_week_amount_minor');
            $t->string('currency', 3)->default('USD');
            $t->boolean('is_test')->default(true);
            $t->timestampTz('created_at');
        });
        Schema::create('billing_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->nullable()->index();
            $t->unsignedInteger('price_version');
            $t->unsignedInteger('regular_amount_minor');
            $t->unsignedInteger('first_week_amount_minor');
            $t->string('currency', 3);
            $t->boolean('is_test');
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at');
        });
        Schema::create('billing_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->index();
            $t->uuid('first_order_id');
            $t->string('provider_id')->nullable()->unique();
            $t->string('customer_id')->nullable();
            $t->string('state', 24);
            $t->string('provider_status', 40)->nullable();
            $t->unsignedInteger('price_version');
            $t->unsignedInteger('regular_amount_minor');
            $t->unsignedInteger('first_week_amount_minor');
            $t->string('currency', 3);
            $t->boolean('is_test');
            $t->boolean('desired_renewal')->nullable();
            $t->boolean('renewal_enabled')->default(false);
            $t->boolean('resolution_required')->default(true);
            $t->boolean('refund_stopped')->default(false);
            $t->timestampTz('period_start')->nullable();
            $t->timestampTz('period_end')->nullable();
            $t->unsignedInteger('confirmation_version')->default(1);
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
        });
        DB::statement("CREATE UNIQUE INDEX billing_one_open_subscription ON billing_subscriptions (viewer_id) WHERE state <> 'ended'");
        Schema::create('billing_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->index();
            $t->uuid('subscription_id')->index();
            $t->uuid('offer_id')->nullable();
            $t->string('idempotency_key', 200);
            $t->string('fingerprint', 64);
            $t->string('transaction_kind', 32);
            $t->unsignedInteger('price_version');
            $t->unsignedInteger('amount_minor');
            $t->unsignedInteger('regular_amount_minor');
            $t->boolean('discount_applied');
            $t->string('currency', 3);
            $t->boolean('is_test');
            $t->string('state', 24);
            $t->string('payment_state', 24)->default('pending');
            $t->string('checkout_id')->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->string('return_to', 1024);
            $t->timestampTz('expires_at')->nullable();
            $t->jsonb('attribution_snapshot')->nullable();
            $t->string('last_error', 80)->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
            $t->unique(['viewer_id', 'idempotency_key']);
        });
        Schema::create('billing_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->unique();
            $t->uuid('subscription_id')->index();
            $t->uuid('viewer_id')->index();
            $t->string('provider_invoice_id')->unique();
            $t->string('provider_charge_id')->nullable()->unique();
            $t->unsignedInteger('amount_minor');
            $t->unsignedInteger('refunded_amount_minor')->default(0);
            $t->string('currency', 3);
            $t->boolean('is_test');
            $t->timestampTz('period_start');
            $t->timestampTz('period_end');
            $t->timestampTz('paid_at');
            $t->timestampTz('created_at');
        });
        Schema::create('billing_entitlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('payment_id')->unique();
            $t->uuid('viewer_id')->index();
            $t->uuid('subscription_id')->index();
            $t->timestampTz('access_from');
            $t->timestampTz('access_until');
            $t->timestampTz('revoked_at')->nullable();
        });
        Schema::create('billing_resume_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->index();
            $t->uuid('subscription_id');
            $t->unsignedInteger('confirmation_version');
            $t->unsignedInteger('amount_minor');
            $t->string('currency', 3);
            $t->timestampTz('next_renewal_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at');
            $t->string('state', 24)->default('issued');
            $t->unsignedInteger('completed_version')->nullable();
        });
        Schema::create('billing_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->index();
            $t->uuid('subscription_id')->index();
            $t->uuid('quote_id')->nullable()->unique();
            $t->string('kind', 24);
            $t->unsignedInteger('expected_version');
            $t->string('state', 24);
            $t->string('last_error', 80)->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
        });
        DB::statement("CREATE UNIQUE INDEX billing_one_pending_operation ON billing_operations (subscription_id) WHERE state = 'pending'");
        Schema::create('billing_inbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('is_test');
            $t->string('provider_event_id')->unique();
            $t->string('event_type');
            $t->string('object_id');
            $t->string('related_object_id')->nullable();
            $t->string('state', 24)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 80)->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at');
            $t->timestampTz('processed_at')->nullable();
        });
        Schema::create('billing_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('payment_id')->unique();
            $t->jsonb('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 80)->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('delivered_at')->nullable();
        });
        Schema::create('billing_refunds', function (Blueprint $t) {
            $t->string('provider_refund_id')->primary();
            $t->uuid('payment_id')->index();
            $t->unsignedInteger('amount_minor');
            $t->string('status', 24);
            $t->timestampTz('created_at');
        });
        Schema::create('billing_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('viewer_id')->index();
            $t->uuid('order_id')->nullable();
            $t->uuid('staff_id');
            $t->boolean('identity_verified')->default(false);
            $t->boolean('cancel_requested')->default(false);
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('created_at');
        });
        Schema::create('billing_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('case_id')->index();
            $t->uuid('staff_id');
            $t->text('body');
            $t->timestampTz('created_at');
        });
        DB::statement('ALTER TABLE billing_prices ADD CONSTRAINT billing_positive_prices CHECK (first_week_amount_minor > 0 AND regular_amount_minor >= first_week_amount_minor AND currency = \'USD\')');
        DB::statement('ALTER TABLE billing_entitlements ADD CONSTRAINT billing_valid_interval CHECK (access_until > access_from)');
        DB::statement('ALTER TABLE billing_payments ADD CONSTRAINT billing_valid_payment CHECK (amount_minor > 0 AND refunded_amount_minor <= amount_minor AND period_end > period_start)');
    }

    public function down(): void
    {
        foreach (['billing_notes', 'billing_cases', 'billing_refunds', 'billing_outbox', 'billing_inbox', 'billing_operations', 'billing_resume_quotes', 'billing_entitlements', 'billing_payments', 'billing_orders', 'billing_subscriptions', 'billing_offers', 'billing_prices', 'billing_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
