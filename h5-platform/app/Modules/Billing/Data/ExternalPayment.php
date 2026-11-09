<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Gateway\ProviderScope;
use DateTimeImmutable;
use DateTimeZone;
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
            || $regularAmountMinor > 99999999 || ! self::validPeriod($scope, $firstCycle, $periodStart, $periodEnd)) {
            throw new InvalidArgumentException('BILLING_PAYMENT_FACT_INVALID');
        }
    }

    public static function validPeriod(ProviderScope $scope, bool $firstCycle, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($scope->provider !== 'paypal' || ! $firstCycle) {
            return $seconds === 604800;
        }
        $utc = new DateTimeZone('UTC');
        $weeklyBoundary = $start->setTimezone($utc)->modify('+7 days');
        if ($seconds > 0 && $end <= $weeklyBoundary) {
            return true;
        }

        // Live can report a later time on the original weekly billing date.
        return $scope->environment === 'live' && $seconds > 604800
            && $end->setTimezone($utc)->format('Y-m-d') === $weeklyBoundary->format('Y-m-d');
    }
}
