<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_promotion_pageviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('landing_key')->unique();
            $t->uuid('source_id')->unique();
            $t->uuid('link_id')->index();
            $t->uuid('drama_id');
            $t->uuid('pixel_id');
            $t->unsignedInteger('pixel_version');
            $t->uuid('privacy_id');
            $t->timestampTz('privacy_updated_at', 6);
            $t->text('context_encrypted')->nullable();
            $t->timestampTz('event_at', 6);
            $t->timestampTz('expires_at', 6)->index();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('attempted_at', 6)->nullable();
            $t->string('state', 24);
            $t->string('reason', 80)->nullable();
            $t->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_promotion_pageviews');
    }
};
