<?php

namespace App\Data;

final readonly class ProgressInput extends ContractData
{
    public function __construct(
        public string $episode_id,
        public int $position_seconds,
        public string $play_session_id,
        public int $sequence
    ) {}
}
