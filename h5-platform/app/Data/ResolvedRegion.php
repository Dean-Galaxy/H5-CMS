<?php

namespace App\Data;

final readonly class ResolvedRegion extends ContractData
{
    public function __construct(
        public string $country_code,
        public string $source
    ) {}
}
