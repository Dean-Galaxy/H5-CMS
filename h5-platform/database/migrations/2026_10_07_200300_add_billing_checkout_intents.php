<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_offers', function (Blueprint $t) {
            $t->boolean('eligible_first_offer')->nullable();
        });
        Schema::table('billing_orders', function (Blueprint $t) {
            $t->jsonb('checkout_request')->nullable();
            $t->timestampTz('checkout_prepared_at')->nullable();
            $t->timestampTz('checkout_attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', fn (Blueprint $t) => $t->dropColumn(['checkout_request', 'checkout_prepared_at', 'checkout_attempted_at']));
        Schema::table('billing_offers', fn (Blueprint $t) => $t->dropColumn('eligible_first_offer'));
    }
};
