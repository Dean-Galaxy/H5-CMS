<?php

namespace App\Modules\Acquisition\Production;

use App\Data\PaymentSettled;
use App\Enums\BehaviorRecordingResult;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final class AutomaticPurchaseWorker
{
    public function run(int $limit): array
    {
        $policy = app(RuntimeEnvironmentGuard::class)->productionAutomaticPurchasePolicy();
        if ($policy === null) {
            return $this->summary('inactive');
        }

        $summary = $this->summary('waiting');
        $limit = max(1, min($limit, 5));
        if (app(PilotScope::class)->siteAnalyticsPolicy() === $policy) {
            try {
                $summary['site_analytics'] = $policy['mode'] === 'single'
                    ? $this->recordSitePurchase($policy)
                    : $this->recordContinuousSitePurchases($policy, $limit);
            } catch (Throwable) {
                $summary['site_analytics'] = 'retry';
            }
        }
        foreach ($this->due($policy, $limit) as $row) {
            $summary['selected']++;
            // Rotate invalid or temporarily unavailable candidates without rewriting the financial outbox.
            DB::table('acquisition_automatic_purchases')->upsert([
                ['payment_id' => $row->payment_id, 'order_id' => $row->order_id, 'checked_at' => now()],
            ], ['payment_id'], ['checked_at']);

            try {
                $result = app(SinglePurchaseSender::class)->sendAutomatically($row->payment_id, $row->order_id, $row->link_id);
                $summary[$result['state']]++;
            } catch (QueryException $e) {
                throw $e;
            } catch (LogicException|RuntimeException $e) {
                // An outbox row can cease to qualify before reservation. Membership is already committed.
                if (! preg_match('/\A(?:BILLING_PILOT|PRODUCTION_(?:PURCHASE|AUTOMATIC))_[A-Z0-9_]+\z/D', $e->getMessage())) {
                    throw $e;
                }
                $summary['blocked']++;
            }
        }

        return $summary;
    }

    private function summary(string $state): array
    {
        return ['state' => $state, 'selected' => 0, 'api_accepted' => 0, 'blocked' => 0, 'rejected' => 0, 'unconfirmed' => 0];
    }

    private function recordSitePurchase(array $policy): string
    {
        // The durable PayPal facts still exist after the independent advertising consumer finishes.
        $row = $this->sitePurchases($policy)->where('p.viewer_id', $policy['viewer_id'])
            ->where('p.paid_at', '<', $policy['until'])->orderBy('p.paid_at')->first();
        if (! $row) {
            return 'waiting';
        }

        return $this->recordSitePayment($row);
    }

    private function recordContinuousSitePurchases(array $policy, int $limit): string
    {
        $rows = $this->sitePurchases($policy)
            ->join('acquisition_analytics_orders as m', 'm.order_id', '=', 'p.order_id')
            ->leftJoin('acquisition_production_purchase as pilot', 'pilot.payment_id', '=', 'p.id')
            ->leftJoin('acquisition_automatic_purchases as a', 'a.payment_id', '=', 'p.id')
            ->whereColumn('m.viewer_id', 'p.viewer_id')->where('m.checkout_at', '>=', $policy['from'])
            ->whereNull('pilot.slot')->whereNull('a.run_id')->whereNull('m.purchase_processed_at')
            ->where(fn ($q) => $q->whereNull('m.purchase_checked_at')->orWhere('m.purchase_checked_at', '<=', now()->subSeconds(45)))
            ->orderByRaw('m.purchase_checked_at ASC NULLS FIRST')->orderBy('p.paid_at')->orderBy('p.id')
            ->limit($limit)->get();
        $priority = ['waiting' => 0, 'skipped' => 1, 'accepted' => 2, 'blocked' => 3, 'retry' => 4];
        $summary = 'waiting';
        foreach ($rows as $row) {
            $mapping = DB::table('acquisition_analytics_orders')->where('order_id', $row->order_id)->whereNull('purchase_processed_at');
            $mapping->update(['purchase_checked_at' => now()]);
            $result = $this->recordSitePayment($row);
            if ($result !== BehaviorRecordingResult::Retry->value) {
                $mapping->update(['purchase_processed_at' => now()]);
            }
            if ($priority[$result] > $priority[$summary]) {
                $summary = $result;
            }
        }

        return $summary;
    }

    /** Use the same settled outbox read as the single-visitor site analytics trial. */
    private function sitePurchases(array $policy): Builder
    {
        return DB::table('billing_outbox as b')
            ->join('billing_payments as p', 'p.id', '=', 'b.payment_id')
            ->join('billing_orders as o', 'o.id', '=', 'p.order_id')
            ->where(['p.provider' => 'paypal', 'p.provider_environment' => 'live', 'p.is_test' => false,
                'p.merchant_scope' => config('billing.paypal_live.merchant_scope'),
                'o.transaction_kind' => TransactionKind::FirstSubscription->value, 'o.state' => 'paid', 'o.payment_state' => 'succeeded'])
            ->whereColumn('p.viewer_id', 'o.viewer_id')
            ->where('b.created_at', '>=', $policy['from'])->where('o.created_at', '>=', $policy['from'])
            ->where('p.paid_at', '>=', $policy['from'])->where('p.paid_at', '<=', now())
            ->select('p.id', 'p.order_id', 'b.payload');
    }

    private function recordSitePayment(object $row): string
    {
        if (DB::table('acquisition_behaviors')->where('dedup_key', hash('sha256', 'payment_confirmed:payment:'.$row->id))->exists()) {
            return 'accepted';
        }

        try {
            $event = PaymentSettled::fromArray(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));
        } catch (\InvalidArgumentException|\JsonException|\TypeError|\ValueError) {
            return 'blocked';
        }
        if ($event->payment_id !== $row->id || $event->order_id !== $row->order_id
            || $event->schema_version !== 'PaymentSettled.v1' || $event->is_test
            || $event->transaction_kind !== TransactionKind::FirstSubscription) {
            return 'blocked';
        }

        return app(BehaviorRecorder::class)->recordProductionPurchase($event)->value;
    }

    private function due(array $policy, int $limit): iterable
    {
        $from = now()->subDay()->max(CarbonImmutable::parse($policy['from']));
        $query = DB::table('billing_outbox as b')
            ->join('billing_payments as p', 'p.id', '=', 'b.payment_id')
            ->join('billing_orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('acquisition_automatic_purchases as a', 'a.payment_id', '=', 'p.id')
            ->leftJoin('acquisition_deliveries as d', 'd.id', '=', 'a.delivery_id')
            ->leftJoin('acquisition_production_purchase as pilot', 'pilot.payment_id', '=', 'p.id')
            ->whereNull('pilot.slot')
            ->where(fn ($q) => $q->whereNull('b.delivered_at')->orWhere('d.state', 'production_ready'))
            ->where(fn ($q) => $q->whereNull('a.checked_at')->orWhere('a.checked_at', '<=', now()->subSeconds(45)))
            ->where(['p.provider' => 'paypal', 'p.provider_environment' => 'live', 'p.is_test' => false, 'p.currency' => 'USD',
                'p.merchant_scope' => config('billing.paypal_live.merchant_scope'), 'o.transaction_kind' => TransactionKind::FirstSubscription->value])
            ->where('p.paid_at', '>=', $from)->where('p.paid_at', '<=', now())
            ->where('o.created_at', '>=', $policy['from'])->where('b.created_at', '>=', $policy['from'])
            ->whereNotNull('o.attribution_snapshot->link_id')->whereIn('o.attribution_snapshot->channel', ['meta', 'tiktok']);

        if ($policy['mode'] === 'single') {
            $binding = DB::table('acquisition_automatic_purchases')->where('run_id', $policy['run_id'])->first();
            if ($binding !== null) {
                $query->where('p.id', $binding->payment_id);
            }
            $query->where('p.viewer_id', $policy['viewer_id'])->where('o.attribution_snapshot->link_id', $policy['link_id'])
                ->where('p.paid_at', '<', $policy['until']);
        } else {
            $query->whereNull('a.run_id');
        }

        return $query->orderByRaw('a.checked_at ASC NULLS FIRST')->orderBy('b.created_at')->orderBy('b.id')->limit($limit)
            ->get(['p.id as payment_id', 'p.order_id', 'o.attribution_snapshot->link_id as link_id'])
            ->filter(fn (object $row) => is_string($row->link_id) && Str::isUuid($row->link_id));
    }
}
