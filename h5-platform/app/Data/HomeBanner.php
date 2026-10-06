<?php

namespace App\Data;

final readonly class HomeBanner extends ContractData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $image_url,
        public string $target_drama_id,
        public int $sort_order
    ) {}
}
