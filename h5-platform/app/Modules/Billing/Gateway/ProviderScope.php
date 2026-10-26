<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway;

use InvalidArgumentException;

/** Persistent identity of a provider account, never selected from an incoming request. */
final readonly class ProviderScope
{
    public function __construct(public string $provider, public string $environment, public string $merchantScope)
    {
        if (! in_array([$provider, $environment], [['stripe', 'test'], ['paypal', 'sandbox']], true)
            || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $merchantScope) !== 1) {
            throw new InvalidArgumentException('BILLING_PROVIDER_SCOPE_INVALID');
        }
    }

    public static function fromRecord(object $record): self
    {
        return new self($record->provider, $record->provider_environment, $record->merchant_scope);
    }

    public function columns(): array
    {
        return ['provider' => $this->provider, 'provider_environment' => $this->environment, 'merchant_scope' => $this->merchantScope];
    }
}
