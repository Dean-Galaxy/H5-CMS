<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Gateway\ProviderScope;
use DateTimeImmutable;
use InvalidArgumentException;

/** Internal normalized paid fact. Only a server-side provider verifier may construct it. */
final readonly class ExternalPayment
{
    public function __construct(
        public ProviderScope $scope,
        public string $reference,
        public string $chargeId,
        public string $externalSubscriptionId,
        public string $customerId,
        public string $firstOrderId,
        public bool $firstCycle,
        public int $amountMinor,
        public int $regularAmountMinor,
        public string $currency,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $paidAt,
    ) {
        foreach ([$reference, $chargeId, $externalSubscriptionId, $customerId] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $identifier) !== 1) {
                throw new InvalidArgumentException('BILLING_PAYMENT_FACT_INVALID');
            }
        }
        if ($currency !== 'USD' || $amountMinor < 1 || $regularAmountMinor < $amountMinor
            || $regularAmountMinor > 99999999 || $periodEnd->getTimestamp() - $periodStart->getTimestamp() !== 604800) {
            throw new InvalidArgumentException('BILLING_PAYMENT_FACT_INVALID');
        }
    }
}
