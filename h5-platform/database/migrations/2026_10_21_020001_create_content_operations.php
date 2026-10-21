<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('drama_id')->index();
            $table->string('action', 80);
            $table->string('resource_type', 40);
            $table->uuid('resource_id');
            $table->unsignedInteger('version');
            $table->timestampTz('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_operations');
    }
};
