<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use App\Modules\Billing\Data\ExternalPayment;
use App\Modules\Billing\Gateway\ProviderScope;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Proves an uninterrupted sequence only. Missing/retried/combined periods require reconciliation. */
final class WeeklyCycleEvidence
{
    public function payments(object $local, object $order, array $remote, array $transactions, array $sales, array $known = []): array
    {
        $info = $remote['billing_info'] ?? [];
        $this->check(($info['failed_payments_count'] ?? null) === 0 && ! isset($info['last_failed_payment'])
            && $this->money($info['outstanding_balance'] ?? []) === 0);
        $start = self::time($remote['start_time'] ?? null);
        $cycles = $info['cycle_executions'] ?? null;
        $this->check(is_array($cycles) && count($cycles) === ($order->discount_applied ? 2 : 1));
        $executed = 0;
        foreach ($cycles as $i => $cycle) {
            $trial = $order->discount_applied && $i === 0;
            $n = $cycle['cycles_completed'] ?? null;
            $this->check(($cycle['sequence'] ?? null) === $i + 1 && ($cycle['tenure_type'] ?? null) === ($trial ? 'TRIAL' : 'REGULAR')
                && is_int($n) && $n >= 0 && (! $trial || $n === 1) && ($cycle['total_cycles'] ?? null) === ($trial ? 1 : 0));
            $executed += $n;
        }
        $this->check($executed > 0 && count($transactions) === $executed && count($sales) === $executed);
        $end = self::time($info['next_billing_time'] ?? null);
        usort($transactions, fn ($a, $b) => self::time($a['time'] ?? null) <=> self::time($b['time'] ?? null));
        // A single completed execution has an unambiguous provider-reported next boundary.
        // Persist that first boundary. Later reads must never shift an already paid interval.
        $firstKnown = $known[$transactions[0]['id'] ?? ''] ?? null;
        if ($firstKnown) {
            $this->check(CarbonImmutable::parse($firstKnown->period_start)->equalTo($start));
            $firstEnd = CarbonImmutable::parse($firstKnown->period_end)->utc();
        } elseif ($executed === 1) {
            $firstEnd = $end;
        } else {
            // Without a first-cycle observation, only the original exact WEEK sequence is provable.
            $firstEnd = $start->addSeconds(604800);
        }
        $this->check($firstEnd->gt($start) && $firstEnd->lte($start->addSeconds(604800))
            && $end->equalTo($firstEnd->addSeconds(($executed - 1) * 604800)));
        $facts = [];
        $ids = [];
        foreach ($transactions as $i => $transaction) {
            $id = $transaction['id'] ?? null;
            $sale = is_string($id) ? ($sales[$id] ?? null) : null;
            $this->check(is_array($sale) && ! isset($ids[$id]) && in_array($transaction['status'] ?? null, isset($known[$id]) ? ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED'] : ['COMPLETED'], true)
                && ($sale['id'] ?? null) === $id && in_array($sale['state'] ?? null, isset($known[$id]) ? ['completed', 'refunded', 'partially_refunded'] : ['completed'], true)
                && ($sale['billing_agreement_id'] ?? null) === $local->provider_id
                && ! isset($sale['payment_hold_status']) && ! isset($sale['reason_code']));
            $ids[$id] = true;
            $amount = $i === 0 ? $order->amount_minor : $local->regular_amount_minor;
            $this->check($this->money($transaction['amount_with_breakdown']['gross_amount'] ?? []) === $amount
                && PayPalAmount::minor($sale['amount']['total'] ?? null, $sale['amount']['currency'] ?? null) === $amount);
            $paid = self::time($transaction['time'] ?? null);
            $this->check(self::time($sale['create_time'] ?? null)->equalTo($paid));
            $from = $i === 0 ? $start : $firstEnd->addSeconds(($i - 1) * 604800);
            $until = $i === 0 ? $firstEnd : $from->addSeconds(604800);
            // Time alone NEVER chooses a period: execution counts, anchor, next boundary,
            // complete history and an independently read Sale must all agree first.
            $this->check($paid->gte($from) && $paid->lt($until));
            $facts[] = new ExternalPayment(ProviderScope::fromRecord($local), $id, $id, $local->provider_id,
                $remote['subscriber']['payer_id'] ?? '', $order->id, $i === 0, $amount, $local->regular_amount_minor, 'USD',
                $from->toDateTimeImmutable(), $until->toDateTimeImmutable(), $paid->toDateTimeImmutable());
        }
        $last = end($facts);
        $this->check(self::time($info['last_payment']['time'] ?? null)->equalTo($last->paidAt)
            && $this->money($info['last_payment']['amount'] ?? []) === $last->amountMinor);

        return $facts;
    }

    public static function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D', $value) !== 1) {
            throw new RuntimeException('PAYPAL_PERIOD_RECONCILIATION_REQUIRED');
        }
        $parsed = \DateTimeImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new RuntimeException('PAYPAL_PERIOD_RECONCILIATION_REQUIRED');
        }

        return CarbonImmutable::instance($parsed)->utc();
    }

    private function money(array $value): int
    {
        return PayPalAmount::minor($value['value'] ?? null, $value['currency_code'] ?? null);
    }

    private function check(bool $valid): void
    {
        if (! $valid) {
            throw new RuntimeException('PAYPAL_PERIOD_RECONCILIATION_REQUIRED');
        }
    }
}
