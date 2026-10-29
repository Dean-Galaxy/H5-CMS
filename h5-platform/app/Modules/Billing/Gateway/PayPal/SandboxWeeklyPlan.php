<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use InvalidArgumentException;

/** Pure request builder, not registered as a gateway or permission to create assets. */
final readonly class SandboxWeeklyPlan
{
    public function __construct(
        public int $priceVersion,
        public int $regularAmountMinor,
        public int $firstWeekAmountMinor,
        public bool $discountApplied,
    ) {
        if ($priceVersion < 1 || $regularAmountMinor < 1 || $regularAmountMinor > PayPalAmount::MAX_MINOR
            || $firstWeekAmountMinor < 1 || $firstWeekAmountMinor > $regularAmountMinor) {
            throw new InvalidArgumentException('PAYPAL_PLAN_SNAPSHOT_INVALID');
        }
    }

    /** Eligibility is decided under the existing account lock, never in this builder. */
    public function createRequest(string $productId): array
    {
        if (preg_match('/\APROD-[A-Z0-9]{17}\z/D', $productId) !== 1) {
            throw new InvalidArgumentException('PAYPAL_PRODUCT_ID_INVALID');
        }
        $cycles = [];
        if ($this->discountApplied) {
            $cycles[] = $this->cycle('TRIAL', 1, 1, $this->firstWeekAmountMinor);
        }
        $cycles[] = $this->cycle('REGULAR', count($cycles) + 1, 0, $this->regularAmountMinor);

        return [
            'product_id' => $productId,
            'name' => 'TEST H5 weekly v'.$this->priceVersion.($this->discountApplied ? ' first-week' : ' regular'),
            'status' => 'ACTIVE',
            'quantity_supported' => false,
            'billing_cycles' => $cycles,
            // Candidate policy 03-004; no runtime binding before contract acceptance.
            'payment_preferences' => ['auto_bill_outstanding' => false, 'payment_failure_threshold' => 1],
        ];
    }

    /** Include the full immutable request; merchant/environment scope is added by persistence. */
    public function fingerprint(string $productId): string
    {
        return hash('sha256', json_encode($this->createRequest($productId), JSON_THROW_ON_ERROR));
    }

    private function cycle(string $tenure, int $sequence, int $count, int $amount): array
    {
        return [
            'frequency' => ['interval_unit' => 'WEEK', 'interval_count' => 1],
            'tenure_type' => $tenure,
            'sequence' => $sequence,
            'total_cycles' => $count,
            'pricing_scheme' => ['fixed_price' => ['currency_code' => 'USD', 'value' => PayPalAmount::decimal($amount)]],
        ];
    }
}
