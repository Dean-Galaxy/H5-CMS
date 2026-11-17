<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('paypal_refund_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', fn (Blueprint $table) => $table->dropColumn('paypal_refund_revision'));
    }
};
