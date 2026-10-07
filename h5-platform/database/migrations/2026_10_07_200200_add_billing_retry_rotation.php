<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['billing_inbox', 'billing_outbox'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestampTz('last_attempted_at')->nullable()->index());
        }
        foreach (['billing_orders', 'billing_subscriptions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestampTz('last_reconciled_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['billing_inbox', 'billing_outbox'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('last_attempted_at'));
        }
        foreach (['billing_orders', 'billing_subscriptions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('last_reconciled_at'));
        }
    }
};
