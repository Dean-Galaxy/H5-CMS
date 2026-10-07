<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('default_free_count')->default(5);
            $t->unsignedBigInteger('version')->default(1);
            $t->unsignedBigInteger('catalog_version')->default(1);
        });
        DB::table('content_settings')->insert(['id' => 1]);
        Schema::create('content_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('created_by');
            $t->string('kind', 20);
            $t->string('state', 20)->default('uploading');
            $t->unsignedBigInteger('expected_bytes');
            $t->unsignedBigInteger('received_bytes')->default(0);
            $t->string('extension', 8);
            $t->string('source_key')->nullable();
            $t->string('output_key')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->string('error_code', 80)->nullable();
            $t->uuid('processing_token')->nullable();
            $t->timestampTz('processing_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampsTz();
            $t->index(['state', 'created_at']);
        });
        Schema::create('content_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name_en', 160)->default('');
            $t->string('name_zh', 160)->default('');
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('content_dramas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('created_by');
            $t->string('title_en', 200)->default('');
            $t->string('title_zh', 200)->default('');
            $t->text('synopsis_en')->default('');
            $t->text('synopsis_zh')->default('');
            $t->uuid('cover_asset_id')->nullable()->references('id')->on('content_assets');
            $t->unsignedInteger('total_episodes')->nullable();
            $t->unsignedInteger('free_count')->default(5);
            $t->boolean('free_overridden')->default(false);
            $t->jsonb('regions')->default('[]');
            $t->timestampTz('license_from')->nullable();
            $t->timestampTz('license_until')->nullable();
            $t->text('rights_note')->default('');
            $t->string('state', 20)->default('draft');
            $t->boolean('recommended')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('access_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('content_drama_categories', function (Blueprint $t) {
            $t->uuid('drama_id')->references('id')->on('content_dramas');
            $t->uuid('category_id')->references('id')->on('content_categories');
            $t->primary(['drama_id', 'category_id']);
        });
        Schema::create('content_episodes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('drama_id')->references('id')->on('content_dramas');
            $t->unsignedInteger('number');
            $t->string('title_en', 200)->default('');
            $t->string('title_zh', 200)->default('');
            $t->uuid('video_asset_id')->nullable()->references('id')->on('content_assets');
            $t->uuid('subtitle_asset_id')->nullable()->references('id')->on('content_assets');
            $t->string('subtitle_language', 16)->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['drama_id', 'number']);
        });
        Schema::create('content_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('drama_id')->unique()->references('id')->on('content_dramas');
            $t->string('mode', 20)->default('completed');
            $t->unsignedInteger('interval_days')->default(1);
            $t->unsignedInteger('batch_size')->default(10);
            $t->unsignedInteger('start_number')->default(1);
            $t->timestampTz('launch_at');
            $t->timestampTz('next_at')->nullable();
            $t->string('state', 30)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->string('last_error', 120)->nullable();
            $t->timestampsTz();
        });
        Schema::create('content_publications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('drama_id')->references('id')->on('content_dramas');
            $t->uuid('plan_id')->nullable()->references('id')->on('content_plans');
            $t->unsignedInteger('plan_version')->nullable();
            $t->timestampTz('scheduled_at')->nullable();
            $t->timestampTz('executed_at');
            $t->string('source', 20);
            $t->string('result', 30);
            $t->jsonb('episode_numbers')->default('[]');
            $t->string('error_code', 120)->nullable();
            $t->unique(['plan_id', 'plan_version', 'scheduled_at']);
        });
        Schema::create('content_banners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('drama_id')->references('id')->on('content_dramas');
            $t->uuid('image_asset_id')->references('id')->on('content_assets');
            $t->string('title_en', 200)->default('');
            $t->string('title_zh', 200)->default('');
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('content_play_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('episode_id')->references('id')->on('content_episodes');
            $t->uuid('viewer_id')->nullable();
            $t->string('browser_hash', 64);
            $t->string('region', 2);
            $t->string('basis', 20);
            $t->unsignedInteger('access_version');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('until');
        });
        Schema::create('content_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->references('id')->on('content_play_sessions');
            $t->uuid('asset_id')->references('id')->on('content_assets');
            $t->timestampTz('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['grants', 'play_sessions', 'banners', 'publications', 'plans', 'episodes', 'drama_categories', 'dramas', 'categories', 'assets', 'settings'] as $name) {
            Schema::dropIfExists('content_'.$name);
        }
    }
};
