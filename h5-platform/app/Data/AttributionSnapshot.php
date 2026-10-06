<?php

namespace App\Data;

final readonly class AttributionSnapshot extends ContractData
{
    public function __construct(
        public string $source_id,
        public string $link_id,
        public string $channel,
        public int $pixel_config_version,
        public string $captured_at,
        public string $consent
    ) {}
}
