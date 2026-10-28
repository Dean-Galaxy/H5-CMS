<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_paypal_merchant_bindings', function (Blueprint $t) {
            $t->string('merchant_scope', 64)->primary();
            $t->char('client_id_sha256', 64)->unique();
            $t->string('merchant_id', 64);
            $t->string('environment', 16);
            $t->string('binding_source', 32);
            $t->string('evidence_ref', 128);
            $t->char('receipt_sha256', 64);
            $t->timestampTz('verified_at');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('billing_paypal_merchant_bindings')->exists()) {
            throw new RuntimeException('BILLING_PAYPAL_BINDINGS_REQUIRE_REVIEW');
        }
        Schema::drop('billing_paypal_merchant_bindings');
    }
};
