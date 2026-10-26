<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive first stage. Existing IDs, indexes, paid facts and outbox stay intact.
        foreach (['billing_subscriptions', 'billing_orders', 'billing_payments', 'billing_inbox', 'billing_refunds'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('provider', 16)->default('stripe');
                $t->string('provider_environment', 16)->default('test');
                $t->string('merchant_scope', 64)->default('legacy-stripe-test');
            });
        }
        Schema::create('billing_provider_resources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $this->scope($t);
            $t->string('resource_type', 24);
            $t->string('external_id', 128);
            $t->string('owner_type', 24);
            $t->uuid('owner_id');
            $t->timestampTz('created_at');
            $t->unique(['provider', 'provider_environment', 'merchant_scope', 'resource_type', 'external_id'], 'billing_provider_resource_identity');
            $t->unique(['provider', 'provider_environment', 'merchant_scope', 'resource_type', 'owner_type', 'owner_id'], 'billing_provider_resource_owner');
        });
        Schema::create('billing_provider_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $this->scope($t);
            $t->string('operation', 32);
            $t->uuid('owner_id');
            $t->unsignedInteger('operation_version');
            $t->uuid('request_key')->unique();
            $t->char('fingerprint', 64);
            $t->jsonb('request_payload');
            $t->string('state', 24)->default('prepared');
            $t->string('external_id', 128)->nullable();
            $t->timestampTz('first_attempted_at')->nullable();
            $t->timestampTz('retry_before')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
            $t->unique(['provider', 'provider_environment', 'merchant_scope', 'operation', 'owner_id', 'operation_version'], 'billing_provider_intent_identity');
        });
        foreach (['billing_provider_resources', 'billing_provider_intents'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope CHECK ((provider = 'stripe' AND provider_environment = 'test') OR (provider = 'paypal' AND provider_environment = 'sandbox'))");
        }
    }

    private function scope(Blueprint $t): void
    {
        $t->string('provider', 16);
        $t->string('provider_environment', 16);
        $t->string('merchant_scope', 64);
    }

    public function down(): void
    {
        // A rollback must never silently remove evidence written after this upgrade.
        if (DB::table('billing_provider_resources')->exists() || DB::table('billing_provider_intents')->exists()) {
            throw new RuntimeException('BILLING_PROVIDER_ROLLBACK_REQUIRES_REVIEW');
        }
        foreach (['billing_subscriptions', 'billing_orders', 'billing_payments', 'billing_inbox', 'billing_refunds'] as $table) {
            if (DB::table($table)->where('provider', '<>', 'stripe')->orWhere('provider_environment', '<>', 'test')->orWhere('merchant_scope', '<>', 'legacy-stripe-test')->exists()) {
                throw new RuntimeException('BILLING_PROVIDER_ROLLBACK_REQUIRES_REVIEW');
            }
        }
        Schema::drop('billing_provider_intents');
        Schema::drop('billing_provider_resources');
        foreach (['billing_subscriptions', 'billing_orders', 'billing_payments', 'billing_inbox', 'billing_refunds'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['provider', 'provider_environment', 'merchant_scope']));
        }
    }
};
