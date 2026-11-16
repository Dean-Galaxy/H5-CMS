<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE acquisition_production_purchase
            DROP CONSTRAINT acquisition_production_purchase_slot_check,
            ADD CONSTRAINT acquisition_production_purchase_slot_check CHECK (slot IN (1, 2)),
            ADD CONSTRAINT acquisition_production_purchase_payment_unique UNIQUE (payment_id),
            ADD CONSTRAINT acquisition_production_purchase_order_unique UNIQUE (order_id)');
    }

    public function down(): void
    {
        // An occupied TikTok slot must be retained. The old constraint refuses a destructive rollback.
        DB::statement('ALTER TABLE acquisition_production_purchase
            DROP CONSTRAINT acquisition_production_purchase_slot_check,
            ADD CONSTRAINT acquisition_production_purchase_slot_check CHECK (slot = 1),
            DROP CONSTRAINT acquisition_production_purchase_payment_unique,
            DROP CONSTRAINT acquisition_production_purchase_order_unique');
    }
};
