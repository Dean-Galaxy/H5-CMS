<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_viewers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80);
            $t->string('email', 254)->unique();
            $t->timestampTz('email_verified_at')->nullable();
            $t->uuid('verification_nonce')->nullable();
            $t->string('password');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('session_version')->default(1);
            $t->rememberToken();
            $t->timestampsTz();
        });
        Schema::create('identity_staff', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80);
            $t->string('email', 254)->unique();
            $t->string('password');
            $t->string('role', 32);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('session_version')->default(1);
            $t->text('app_authentication_secret')->nullable();
            $t->text('app_authentication_recovery_codes')->nullable();
            $t->rememberToken();
            $t->timestampsTz();
        });
        Schema::create('identity_password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('identity_audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_kind', 16);
            $t->uuid('actor_id')->nullable()->index();
            $t->string('action', 100)->index();
            $t->string('subject_type', 80);
            $t->string('subject_id', 100)->nullable();
            $t->string('result', 16);
            $t->jsonb('changes');
            $t->uuid('request_id')->index();
            $t->timestampTz('occurred_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['identity_audit_events', 'sessions', 'identity_password_reset_tokens', 'identity_staff', 'identity_viewers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
