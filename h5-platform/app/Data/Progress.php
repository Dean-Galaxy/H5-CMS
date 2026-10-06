<?php

namespace App\Data;

final readonly class Progress extends ContractData
{
    public function __construct(
        public string $drama_id,
        public string $episode_id,
        public int $position_seconds,
        public string $play_session_id,
        public int $sequence,
        public string $saved_at
    ) {}
}
