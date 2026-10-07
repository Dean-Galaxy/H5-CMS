<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_deliveries', function (Blueprint $t) {
            $t->text('payload_encrypted')->nullable();
            $t->index(['state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_deliveries', function (Blueprint $t) {
            $t->dropColumn('payload_encrypted');
            $t->dropIndex(['state', 'next_attempt_at']);
        });
    }
};
