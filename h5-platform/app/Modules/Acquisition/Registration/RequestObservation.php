<?php

namespace App\Modules\Acquisition\Registration;

use Illuminate\Http\Request;

/** Ephemeral server request capability. It cannot be persisted as an identity-to-C retry mapping. */
final readonly class RequestObservation
{
    public function __construct(private Request $request, private string $operation, private string $subject, private string $session, private int $revision) {}

    public function belongsTo(Request $request): bool
    {
        return $this->request === $request;
    }

    public function matches(Request $request, array $active, int $revision): bool
    {
        return $request === $this->request && $active[0]->id === $this->subject && $active[1]->id === $this->session && $revision === $this->revision;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Request observations must not be persisted.');
    }
}
