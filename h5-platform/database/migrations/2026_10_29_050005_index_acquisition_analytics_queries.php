<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_behaviors', function (Blueprint $table) {
            $table->index(['subject_id', 'is_test', 'event_name', 'ordered_at', 'sequence'], 'acq_behavior_subject_stage_order');
            $table->index(['link_id', 'occurred_at'], 'acq_behavior_link_period');
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_behaviors', function (Blueprint $table) {
            $table->dropIndex('acq_behavior_subject_stage_order');
            $table->dropIndex('acq_behavior_link_period');
        });
    }
};
