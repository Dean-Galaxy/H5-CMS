<?php

namespace App\Modules\Identity\Services;

use App\Contracts\AuditRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAuditRecorder implements AuditRecorder
{
    public function record(string $action, string $subjectType, ?string $subjectId, string $result, array $changes = []): void
    {
        $staff = Auth::guard('staff')->user();
        $viewer = Auth::guard('web')->user();
        $safe = [];
        foreach (['role', 'previous_role', 'active', 'version', 'session_version', 'error_code', 'guard', 'mail_mode'] as $key) {
            if (array_key_exists($key, $changes) && (is_scalar($changes[$key]) || $changes[$key] === null)) {
                $safe[$key] = is_string($changes[$key]) ? substr($changes[$key], 0, 80) : $changes[$key];
            }
        }
        DB::table('identity_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'actor_kind' => $staff ? 'staff' : ($viewer ? 'viewer' : 'system'),
            'actor_id' => $staff?->id ?? $viewer?->id, 'action' => substr($action, 0, 100),
            'subject_type' => substr($subjectType, 0, 80), 'subject_id' => $subjectId === null ? null : substr($subjectId, 0, 100),
            'result' => $result, 'changes' => json_encode($safe, JSON_THROW_ON_ERROR),
            'request_id' => request()->attributes->get('request_id', (string) Str::uuid7()), 'occurred_at' => now(),
        ]);
    }
}
