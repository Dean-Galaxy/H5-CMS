<?php

namespace App\Data;

use App\Enums\PlaybackReason;

final readonly class PlaybackGrant extends ContractData
{
    public function __construct(
        public ?string $grant_id,
        public string $episode_id,
        public bool $allowed,
        public PlaybackReason $reason,
        public ?string $short_lived_url,
        public ?string $expires_at,
        public ?string $play_session_id
    ) {}

    protected static function types(): array
    {
        return ['reason' => PlaybackReason::class];
    }
}
