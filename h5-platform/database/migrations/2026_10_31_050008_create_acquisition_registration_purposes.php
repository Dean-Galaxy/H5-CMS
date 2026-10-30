<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_r1_proofs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('is_test');
            $t->timestampTz('created_at', 6);
            $t->timestampTz('expires_at', 6)->index();
        });
        Schema::create('acquisition_r1_choices', function (Blueprint $t) {
            $t->foreignUuid('proof_id')->constrained('acquisition_r1_proofs')->cascadeOnDelete();
            $t->string('purpose', 24);
            $t->string('choice', 12);
            // Necessary choice ownership only. No C subject/session/event or conversion reference.
            $t->uuid('owner_id')->nullable();
            $t->unsignedInteger('revision');
            $t->string('notice_version', 64);
            $t->string('policy_version', 64);
            $t->boolean('is_test');
            $t->timestampTz('policy_valid_from', 6)->nullable();
            $t->timestampTz('policy_valid_until', 6)->nullable();
            $t->timestampTz('changed_at', 6);
            $t->timestampTz('expires_at', 6);
            // A later choice-only allow cannot authorize the legacy sender.
            $t->timestampTz('legacy_denied_at', 6)->nullable();
            $t->timestampTz('legacy_denial_until', 6)->nullable();
            $t->primary(['proof_id', 'purpose']);
        });
        Schema::create('acquisition_r1_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('proof_id')->constrained('acquisition_r1_proofs')->cascadeOnDelete();
            $t->uuid('actor_id')->nullable();
            $t->string('purpose', 24);
            $t->char('fingerprint', 64);
            $t->string('status', 12);
            $t->timestampTz('created_at', 6);
            $t->timestampTz('expires_at', 6)->index();
        });
        Schema::table('acquisition_privacy', function (Blueprint $t) {
            $t->timestampTz('r1_advertising_denied_at', 6)->nullable();
            $t->timestampTz('r1_advertising_denial_until', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acquisition_privacy', fn (Blueprint $t) => $t->dropColumn(['r1_advertising_denied_at', 'r1_advertising_denial_until']));
        foreach (['operations', 'choices', 'proofs'] as $name) {
            Schema::dropIfExists('acquisition_r1_'.$name);
        }
    }
};
