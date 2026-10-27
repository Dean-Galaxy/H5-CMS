<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_paypal_observations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('subscription_id')->index();
            $t->string('merchant_scope', 64);
            $t->string('kind', 24);
            $t->string('external_id', 128);
            $t->char('fingerprint', 64)->unique();
            $t->jsonb('facts');
            $t->timestampTz('observed_at');
        });
    }

    public function down(): void
    {
        if (DB::table('billing_paypal_observations')->exists()) {
            throw new RuntimeException('BILLING_PAYPAL_OBSERVATIONS_REQUIRE_REVIEW');
        }
        Schema::drop('billing_paypal_observations');
    }
};
