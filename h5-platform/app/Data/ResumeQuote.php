<?php

namespace App\Data;

final readonly class ResumeQuote extends ContractData
{
    public function __construct(
        public string $quote_id,
        public string $subscription_id,
        public int $confirmation_version,
        public string $currency,
        public string $next_renewal_at,
        public int $next_renewal_amount_minor,
        public string $expires_at
    ) {}
}
