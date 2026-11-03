<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->constraints(true);
    }

    public function down(): void
    {
        foreach (['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_inbox', 'billing_refunds', 'billing_provider_resources', 'billing_provider_intents'] as $table) {
            if (DB::table($table)->where('provider', 'paypal')->where('provider_environment', 'live')->exists()) {
                throw new RuntimeException('BILLING_LIVE_ROLLBACK_REQUIRES_REVIEW');
            }
        }
        if (DB::table('billing_paypal_merchant_bindings')->where('environment', 'live')->exists()) {
            throw new RuntimeException('BILLING_LIVE_ROLLBACK_REQUIRES_REVIEW');
        }
        $this->constraints(false);
    }

    private function constraints(bool $live): void
    {
        $environments = $live ? "'sandbox', 'live'" : "'sandbox'";
        foreach (['billing_provider_resources', 'billing_provider_intents'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_scope");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope CHECK ((provider = 'stripe' AND provider_environment = 'test') OR (provider = 'paypal' AND provider_environment IN ({$environments})))");
        }
    }
};
