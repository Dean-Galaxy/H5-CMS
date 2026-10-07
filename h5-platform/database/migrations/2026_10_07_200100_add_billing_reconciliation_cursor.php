<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', fn (Blueprint $table) => $table->timestampTz('last_reconciled_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('billing_payments', fn (Blueprint $table) => $table->dropColumn('last_reconciled_at'));
    }
};
