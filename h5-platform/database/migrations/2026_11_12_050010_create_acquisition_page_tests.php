<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_page_tests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('pixel_id')->nullable();
            $t->unsignedInteger('pixel_version')->nullable();
            $t->uuid('privacy_id')->nullable();
            $t->text('context_encrypted')->nullable();
            $t->timestampTz('event_at', 6);
            $t->timestampTz('expires_at', 6)->index();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('attempted_at', 6)->nullable();
            $t->string('state', 24);
            $t->string('reason', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_page_tests');
    }
};
