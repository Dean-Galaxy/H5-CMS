<?php

namespace App\Data;

final readonly class PromotableDrama extends ContractData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $locale,
        public string $country_code,
        public int $published_episode_count
    ) {}
}
