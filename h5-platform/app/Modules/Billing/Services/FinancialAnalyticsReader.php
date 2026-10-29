<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Contracts\BillingAnalyticsReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Internal, scoped by its caller; no provider identifier or arbitrary stored JSON escapes this port. */
final class FinancialAnalyticsReader implements BillingAnalyticsReader
{
    public function payments(array $paymentIds): array
    {
        Validator::make(['ids' => $paymentIds], ['ids' => 'array|max:200', 'ids.*' => 'required|uuid'])->validate();
        if ($paymentIds === []) {
            return [];
        }
        $result = [];
        foreach (DB::table('billing_payments as p')->join('billing_orders as o', 'o.id', '=', 'p.order_id')
            ->whereIn('p.id', array_values(array_unique($paymentIds)))
            ->select('p.id', 'p.viewer_id', 'p.order_id', 'p.subscription_id', 'o.transaction_kind', 'p.amount_minor',
                'p.refunded_amount_minor', 'p.currency', 'p.is_test', 'p.paid_at', 'o.attribution_snapshot')->get() as $row) {
            $result[$row->id] = [
                'payment_id' => $row->id, 'viewer_id' => $row->viewer_id, 'order_id' => $row->order_id,
                'subscription_id' => $row->subscription_id, 'transaction_kind' => $row->transaction_kind,
                'amount_minor' => $row->amount_minor, 'refunded_amount_minor' => $row->refunded_amount_minor,
                'currency' => $row->currency, 'is_test' => (bool) $row->is_test, 'paid_at' => BillingStore::iso($row->paid_at),
                'attribution_snapshot' => self::snapshot($row->attribution_snapshot),
            ];
        }

        return $result;
    }

    public static function snapshot(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            $value = BillingStore::json($value);
        } catch (\Throwable) {
            logger()->warning('billing.analytics_snapshot_invalid');

            return null;
        }
        if (! is_array($value) || Validator::make($value, [
            'source_id' => 'required|uuid', 'link_id' => 'required|uuid', 'channel' => 'required|in:meta,tiktok',
            'pixel_config_version' => 'required|integer|min:1', 'captured_at' => 'required|string', 'consent' => 'required|in:granted,denied,unknown',
        ])->fails() || ! self::validCapturedAt($value['captured_at'] ?? null)) {
            logger()->warning('billing.analytics_snapshot_invalid');

            return null;
        }

        return ['source_id' => $value['source_id'], 'link_id' => $value['link_id'], 'channel' => $value['channel'],
            'pixel_config_version' => (int) $value['pixel_config_version'], 'captured_at' => BillingStore::iso($value['captured_at']), 'consent' => $value['consent']];
    }

    private static function validCapturedAt(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/D', $value) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
