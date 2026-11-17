<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_analytics_orders', function (Blueprint $table): void {
            $table->timestampTz('purchase_checked_at')->nullable();
            $table->timestampTz('purchase_processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_analytics_orders', function (Blueprint $table): void {
            $table->dropColumn(['purchase_checked_at', 'purchase_processed_at']);
        });
    }
};
