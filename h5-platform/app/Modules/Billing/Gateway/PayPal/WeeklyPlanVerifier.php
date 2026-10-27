<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use RuntimeException;

/** Re-read the actual immutable plan before offering it. No price override or update API. */
final class WeeklyPlanVerifier
{
    public function assertMatches(array $actual, string $id, SandboxWeeklyPlan $snapshot, string $productId): void
    {
        $expected = $snapshot->createRequest($productId);
        $this->require(($actual['id'] ?? null) === $id && ($actual['status'] ?? null) === 'ACTIVE'
            && ($actual['product_id'] ?? null) === $productId && ($actual['quantity_supported'] ?? null) === false);
        $cycles = $actual['billing_cycles'] ?? null;
        $this->require(is_array($cycles) && array_is_list($cycles) && count($cycles) === count($expected['billing_cycles']));
        foreach ($expected['billing_cycles'] as $i => $cycle) {
            $remote = $cycles[$i];
            $this->require(($remote['frequency']['interval_unit'] ?? null) === 'WEEK' && ($remote['frequency']['interval_count'] ?? null) === 1);
            foreach (['tenure_type', 'sequence', 'total_cycles'] as $key) {
                $this->require(($remote[$key] ?? null) === $cycle[$key]);
            }
            $scheme = $remote['pricing_scheme'] ?? [];
            $this->require(! isset($scheme['tiers']) && ! isset($scheme['pricing_model']));
            $this->require($this->money($scheme['fixed_price'] ?? null) === PayPalAmount::minor($cycle['pricing_scheme']['fixed_price']['value'], 'USD'));
            // A future scheduled price is not the frozen renewal agreement either.
            $this->require(! isset($scheme['next_pricing_scheme']));
        }
        $preferences = $actual['payment_preferences'] ?? [];
        $this->require(($preferences['auto_bill_outstanding'] ?? null) === false && ($preferences['payment_failure_threshold'] ?? null) === 1);
        $this->require(! isset($preferences['setup_fee']) || $this->money($preferences['setup_fee']) === 0);
        $tax = $actual['taxes'] ?? null;
        $this->require($tax === null || (is_array($tax) && in_array($tax['percentage'] ?? null, ['0', '0.0', '0.00'], true)));
    }

    private function money(mixed $money): int
    {
        $this->require(is_array($money) && is_string($money['value'] ?? null) && ($money['currency_code'] ?? null) === 'USD');

        return PayPalAmount::minor($money['value'], $money['currency_code']);
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw new RuntimeException('PAYPAL_PLAN_RECONCILIATION_REQUIRED');
        }
    }
}
