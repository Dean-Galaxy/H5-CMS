<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_privacy', function (Blueprint $t) {
            // Purpose-specific denial only. No C subject/account/order association is added.
            $t->timestampTz('site_analysis_denied_at', 6)->nullable();
            $t->timestampTz('site_analysis_denial_until', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_privacy', fn (Blueprint $t) => $t->dropColumn(['site_analysis_denied_at', 'site_analysis_denial_until']));
    }
};
