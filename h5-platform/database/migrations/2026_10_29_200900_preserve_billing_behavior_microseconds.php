<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen only. Existing second-precision facts keep their original value and JSON.
        DB::statement('ALTER TABLE billing_behavior_outbox ALTER COLUMN occurred_at TYPE timestamp(6) with time zone');
        DB::statement('ALTER TABLE billing_analytics_states ALTER COLUMN observed_at TYPE timestamp(6) with time zone');
    }

    public function down(): void
    {
        // A rollback loses precision; truncate explicitly rather than rounding into a later fact.
        DB::statement("ALTER TABLE billing_behavior_outbox ALTER COLUMN occurred_at TYPE timestamp(0) with time zone USING date_trunc('second', occurred_at)");
        DB::statement("ALTER TABLE billing_analytics_states ALTER COLUMN observed_at TYPE timestamp(0) with time zone USING date_trunc('second', observed_at)");
    }
};
