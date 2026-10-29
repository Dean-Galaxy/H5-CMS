<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'billing_subscriptions' => ['provider_id'],
        'billing_orders' => ['checkout_id'],
        'billing_payments' => ['provider_invoice_id', 'provider_charge_id'],
        'billing_inbox' => ['provider_event_id'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropUnique([$column]);
                    $t->unique(['provider', 'provider_environment', 'merchant_scope', $column], $this->index($t->getTable(), $column));
                });
            }
        }
        Schema::table('billing_refunds', function (Blueprint $t) {
            $t->dropPrimary();
            $t->primary(['provider', 'provider_environment', 'merchant_scope', 'provider_refund_id'], 'billing_refunds_scope_primary');
        });
    }

    public function down(): void
    {
        // Old application code cannot safely process new scopes, even when IDs happen to differ.
        foreach (array_merge(array_keys($this->columns), ['billing_refunds']) as $table) {
            if (DB::table($table)->where('provider', '<>', 'stripe')->orWhere('provider_environment', '<>', 'test')->orWhere('merchant_scope', '<>', 'legacy-stripe-test')->exists()) {
                throw new RuntimeException('BILLING_PROVIDER_ROLLBACK_REQUIRES_REVIEW');
            }
        }
        Schema::table('billing_refunds', function (Blueprint $t) {
            $t->dropPrimary('billing_refunds_scope_primary');
            $t->primary('provider_refund_id');
        });
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropUnique($this->index($t->getTable(), $column));
                    $t->unique($column);
                });
            }
        }
    }

    private function index(string $table, string $column): string
    {
        return 'billing_scope_'.substr(hash('sha256', $table.'.'.$column), 0, 20);
    }
};
