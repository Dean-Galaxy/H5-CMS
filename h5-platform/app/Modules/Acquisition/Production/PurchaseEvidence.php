<?php

namespace App\Modules\Acquisition\Production;

use App\Data\PaymentSettled;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\Analytics\ClickContext;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Services\CheckoutContextStore;
use App\Support\RuntimeEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PurchaseEvidence
{
    public function scope(): array
    {
        if (! PilotScope::enabled() || ! app()->runningInConsole() || ! app()->environment('production')
            || config('acquisition.transport') !== 'disabled' || config('acquisition.external_enabled') !== false) {
            throw new LogicException('PRODUCTION_PURCHASE_RUNTIME_INVALID');
        }
        $scope = app(RuntimeEnvironmentGuard::class)->productionAcquisitionScope();
        if ($scope['platform'] !== 'meta') {
            throw new LogicException('PRODUCTION_PURCHASE_META_ONLY');
        }

        return $scope;
    }

    public function digest(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function requireEvent(PaymentSettled $event, array $approved): void
    {
        if ($approved !== $this->scope() || $event->schema_version !== 'PaymentSettled.v1'
            || $event->is_test || $event->transaction_kind !== TransactionKind::FirstSubscription
            || $event->currency !== 'USD' || $event->amount_minor !== 399
            || $event->attribution_snapshot?->checkout_context_id === null) {
            throw new LogicException('PRODUCTION_PURCHASE_FACT_INVALID');
        }
    }

    public function prepare(object $row, string $paymentId, array $approved): array
    {
        $receipt = DB::table('acquisition_receipts')->where('event_id', $row->receipt_id)->first();
        $source = DB::table('acquisition_sources')->find($row->source_id);
        if ($approved !== $this->scope() || ! $receipt || ! $source || $receipt->payment_id !== $paymentId
            || $receipt->source_status !== 'matched' || $receipt->source_id !== $row->source_id || $receipt->link_id !== $row->link_id
            || $receipt->is_test || $receipt->transaction_kind !== TransactionKind::FirstSubscription->value
            || $receipt->currency !== 'USD' || $receipt->amount_minor !== 399
            || $row->event_key !== 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$paymentId)) {
            throw new LogicException('PRODUCTION_PURCHASE_FACT_INVALID');
        }
        [$scope, $link, $pixel] = app(PilotScope::class)->source($source);
        if ($scope !== $approved || $row->link_id !== $link->id || $row->pixel_id !== $pixel->id
            || (int) $row->pixel_version !== $scope['pixel_version'] || $row->platform !== 'meta'
            || $row->environment !== 'production' || $row->external_id !== $pixel->external_id) {
            throw new LogicException('PRODUCTION_PURCHASE_ASSET_CHANGED');
        }
        $occurred = CarbonImmutable::parse($receipt->occurred_at);
        if ($occurred->lt($scope['from']) || $occurred->gte($scope['until']) || $occurred->gt(now()) || $occurred->lt(now()->subDays(7))) {
            throw new LogicException('PRODUCTION_PURCHASE_EVENT_TIME_INVALID');
        }

        $context = app(CheckoutContextStore::class)->forDelivery($row, $receipt, $source);
        if (($context['_checkout_status'] ?? null) !== 'valid') {
            throw new LogicException('PRODUCTION_PURCHASE_CHECKOUT_INVALID');
        }
        $snapshot = json_decode($receipt->attribution_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $captured = DB::table('acquisition_checkout_contexts')->where('id', $snapshot['checkout_context_id'])->value('captured_at');
        if (! $captured || CarbonImmutable::parse($captured)->lt($source->captured_at) || $occurred->lt($captured)) {
            throw new LogicException('PRODUCTION_PURCHASE_CHECKOUT_INVALID');
        }
        // Revalidate the frozen click values. The adapter only projects the approved Meta fields.
        app(ClickContext::class)->validate(array_intersect_key($context, ['fbc' => true, 'fbp' => true]));

        return app(MetaAdapter::class)->payload($row, $receipt, $context, null);
    }
}
