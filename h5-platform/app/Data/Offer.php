<?php

namespace App\Data;

final readonly class Offer extends ContractData
{
    public function __construct(
        public string $offer_id,
        public int $price_version,
        public string $currency,
        public int $regular_amount_minor,
        public int $first_week_amount_minor,
        public ?bool $eligible_for_first_offer,
        public string $expires_at,
        public int $next_renewal_amount_minor
    ) {}
}
