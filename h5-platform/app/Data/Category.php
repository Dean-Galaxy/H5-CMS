<?php

namespace App\Data;

final readonly class Category extends ContractData
{
    public function __construct(
        public string $id,
        public string $name,
        public int $sort_order
    ) {}
}
