<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_registration_receipts', function (Blueprint $table): void {
            $table->uuid('viewer_id')->primary();
            $table->foreign('viewer_id')->references('id')->on('identity_viewers')->cascadeOnDelete();
            $table->string('terms_version', 64);
            $table->string('privacy_notice_version', 64);
            $table->string('locale', 12);
            $table->timestampTz('accepted_at');
            $table->boolean('is_test')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_registration_receipts');
    }
};
