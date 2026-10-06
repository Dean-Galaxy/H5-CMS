<?php

namespace App\Contracts;

interface AuditRecorder
{
    /** Changes are restricted to the shared allowlist. Never pass request bodies or provider payloads. */
    public function record(string $action, string $subjectType, ?string $subjectId, string $result, array $changes = []): void;
}
