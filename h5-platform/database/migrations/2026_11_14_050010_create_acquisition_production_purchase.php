<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_production_purchase', function (Blueprint $table): void {
            $table->unsignedSmallInteger('slot')->primary();
            $table->uuid('payment_id');
            $table->uuid('order_id');
            $table->uuid('delivery_id')->unique();
            $table->string('scope_digest', 64);
            $table->timestampTz('created_at');
        });
        DB::statement('ALTER TABLE acquisition_production_purchase ADD CHECK (slot = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_production_purchase');
    }
};
