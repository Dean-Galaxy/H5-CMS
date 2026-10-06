<?php

namespace App\Data;

final readonly class CheckoutInput extends ContractData
{
    public function __construct(
        public string $offer_id,
        public int $price_version,
        public string $return_to
    ) {}
}
