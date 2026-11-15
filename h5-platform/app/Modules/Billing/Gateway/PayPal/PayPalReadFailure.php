<?php

namespace App\Modules\Billing\Gateway\PayPal;

use RuntimeException;

/** Contains only a fixed diagnostic, never vendor responses or credentials. */
final class PayPalReadFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $retryAt = null, public readonly bool $deferred = false)
    {
        parent::__construct('PAYPAL_RECONCILIATION_REQUIRED');
    }
}
