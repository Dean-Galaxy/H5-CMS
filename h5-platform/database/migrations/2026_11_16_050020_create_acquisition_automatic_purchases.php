<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_automatic_purchases', function (Blueprint $table): void {
            $table->uuid('payment_id')->primary();
            $table->uuid('order_id')->unique();
            $table->uuid('delivery_id')->nullable()->unique();
            $table->uuid('run_id')->nullable()->unique();
            $table->string('scope_digest', 64)->nullable();
            $table->timestampTz('checked_at')->index();
        });
    }

    public function down(): void
    {
        if (DB::table('acquisition_automatic_purchases')->whereNotNull('delivery_id')->exists()) {
            throw new LogicException('AUTOMATIC_PURCHASE_AUDIT_REQUIRES_RETENTION');
        }

        Schema::dropIfExists('acquisition_automatic_purchases');
    }
};
