<?php

namespace App\Data;

final readonly class ResumeInput extends ContractData
{
    public function __construct(
        public int $confirmation_version,
        public string $confirmed_next_renewal_at,
        public int $confirmed_amount_minor
    ) {}
}
