<?php

namespace App\Data;

final readonly class ViewerIdentity
{
    public function __construct(public string $id, public string $email, public bool $emailVerified) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'email_verified' => $this->emailVerified];
    }
}
