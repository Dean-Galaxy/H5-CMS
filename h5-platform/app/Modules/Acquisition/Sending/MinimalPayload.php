<?php

namespace App\Modules\Acquisition\Sending;

/** Exact allowed shape and trusted values; old frozen bytes are rejected, never migrated in place. */
final class MinimalPayload
{
    public function valid(object $delivery, object $receipt, array $context, array $payload, ?string $testCode): bool
    {
        $adapter = $delivery->platform === 'meta' ? new MetaAdapter : new TikTokAdapter;
        $expected = $adapter->payload($delivery, $receipt, $context, $delivery->environment === 'test' ? $testCode : null);

        return json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)
            === json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
