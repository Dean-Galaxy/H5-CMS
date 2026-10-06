<?php

namespace App\Data;

final readonly class Episode extends ContractData
{
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public int $duration_seconds,
        public string $access_requirement,
        public string $media_state
    ) {}
}
