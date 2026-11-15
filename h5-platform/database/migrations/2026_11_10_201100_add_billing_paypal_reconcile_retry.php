<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->timestampTz('paypal_retry_at')->nullable();
            $table->unsignedSmallInteger('paypal_retry_count')->default(0);
            $table->string('paypal_retry_reason', 32)->nullable();
        });
        Schema::table('billing_inbox', fn (Blueprint $table) => $table->timestampTz('paypal_retry_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('billing_inbox', fn (Blueprint $table) => $table->dropColumn('paypal_retry_at'));
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['paypal_retry_at', 'paypal_retry_count', 'paypal_retry_reason']);
        });
    }
};
