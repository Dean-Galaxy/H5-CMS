<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_analytics_states', function (Blueprint $table) {
            $table->string('aggregate_key', 96)->primary();
            $table->unsignedInteger('fact_version');
            $table->string('value', 24);
            $table->timestampTz('observed_at');
        });
        Schema::create('billing_behavior_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_key', 96)->index();
            $table->unsignedInteger('fact_version');
            $table->string('event_name', 48);
            $table->jsonb('properties');
            $table->string('state', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unique(['aggregate_key', 'fact_version']);
        });
        // Existing financial history is a baseline, never retroactively a consented behavior.
        // Opaque IDs and local confirmed values only; no provider or recorder calls.
        DB::table('billing_payments')->orderBy('id')->chunkById(200, function ($payments) {
            foreach ($payments as $payment) {
                DB::table('billing_analytics_states')->insert(['aggregate_key' => 'refund:'.$payment->id,
                    'fact_version' => 0, 'value' => (string) $payment->refunded_amount_minor, 'observed_at' => now()]);
            }
        });
        DB::table('billing_subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions) {
            foreach ($subscriptions as $sub) {
                $confirmed = $sub->renewal_enabled
                    ? in_array($sub->provider_status, ['active', 'ACTIVE'], true)
                    : in_array($sub->provider_status, ['active', 'canceled', 'incomplete_expired', 'SUSPENDED', 'CANCELLED', 'EXPIRED'], true);
                if ($confirmed) {
                    DB::table('billing_analytics_states')->insert(['aggregate_key' => 'subscription:'.$sub->id,
                        'fact_version' => 0, 'value' => $sub->renewal_enabled ? 'enabled' : 'disabled', 'observed_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_behavior_outbox');
        Schema::dropIfExists('billing_analytics_states');
    }
};
