<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_deliveries', fn (Blueprint $t) => $t->timestampTz('first_attempted_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('acquisition_deliveries', fn (Blueprint $t) => $t->dropColumn('first_attempted_at'));
    }
};
