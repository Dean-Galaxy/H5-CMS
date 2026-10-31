<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_checkout_contexts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('source_id')->index();
            $t->uuid('privacy_id');
            $t->uuid('pixel_id');
            $t->unsignedInteger('pixel_version');
            $t->string('platform', 12);
            $t->text('context_encrypted');
            $t->timestampTz('rendered_at', 6);
            $t->timestampTz('captured_at', 6);
            $t->timestampTz('expires_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_checkout_contexts');
    }
};
