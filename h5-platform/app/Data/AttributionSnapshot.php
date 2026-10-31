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
        public string $consent,
        public ?string $checkout_context_id = null,
    ) {
        if ($checkout_context_id !== null && ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/Di', $checkout_context_id)) {
            throw new \InvalidArgumentException('CHECKOUT_CONTEXT_ID_INVALID');
        }
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->checkout_context_id === null) {
            unset($data['checkout_context_id']);
        }

        return $data;
    }
}
