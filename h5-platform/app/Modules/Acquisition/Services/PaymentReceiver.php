<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\BehaviorEventRecorder;
use App\Data\PaymentSettled;
use App\Enums\BehaviorRecordingResult;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Production\PurchaseEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PaymentReceiver
{
    /** Internal listener only. Persist then return; never contact ad platforms in Billing's transaction. */
    public function handle(PaymentSettled $event): void
    {
        $this->record($event);
    }

    /** Called only by the exact Billing outbox consumer inside its transaction. */
    public function receiveProduction(PaymentSettled $event, array $approved): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('PRODUCTION_PURCHASE_REQUIRES_OUTBOX_TRANSACTION');
        }
        $evidence = app(PurchaseEvidence::class);
        $evidence->requireEvent($event, $approved);

        return DB::transaction(function () use ($event, $approved, $evidence): bool {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('acquisition.production.purchase', 0))");
            if (DB::table('acquisition_production_purchase')->where('slot', 1)->exists()) {
                throw new \LogicException('PRODUCTION_PURCHASE_SLOT_ALREADY_BOUND');
            }

            $id = $this->record($event, production: true);
            $row = $id ? DB::table('acquisition_deliveries')->find($id) : null;
            if (! $row) {
                throw new \LogicException('PRODUCTION_PURCHASE_SOURCE_INVALID');
            }
            $evidence->prepare($row, $event->payment_id, $approved);
            DB::table('acquisition_production_purchase')->insert([
                'slot' => 1, 'payment_id' => $event->payment_id, 'order_id' => $event->order_id,
                'delivery_id' => $id, 'scope_digest' => $evidence->digest($approved), 'created_at' => now(),
            ]);

            return true;
        });
    }

    private function record(PaymentSettled $event, bool $production = false): ?string
    {
        $data = $event->toArray();
        Validator::make($data, [
            'event_id' => ['required', 'uuid'], 'payment_id' => ['required', 'uuid'], 'order_id' => ['required', 'uuid'],
            'subscription_id' => ['required', 'uuid'], 'schema_version' => ['required', 'in:PaymentSettled.v1'],
            'amount_minor' => ['required', 'integer', 'min:0'], 'currency' => ['required', 'in:USD'],
            'occurred_at' => ['required', 'date', 'regex:/^.+(?:Z|[+-]\d\d:\d\d)$/'],
            'attribution_snapshot.source_id' => ['sometimes', 'required', 'uuid'],
            'attribution_snapshot.link_id' => ['sometimes', 'required', 'uuid'],
            'attribution_snapshot.channel' => ['sometimes', 'required', 'in:meta,tiktok'],
            'attribution_snapshot.pixel_config_version' => ['sometimes', 'required', 'integer', 'min:1'],
            'attribution_snapshot.captured_at' => ['sometimes', 'required', 'date'],
            'attribution_snapshot.consent' => ['sometimes', 'required', 'in:granted,denied,unknown'],
            'attribution_snapshot.checkout_context_id' => ['sometimes', 'required', 'uuid'],
        ])->validate();
        $fingerprintData = $data;
        unset($fingerprintData['event_id']);
        $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($event, $fingerprint, $production) {
            // Serializes even before a row exists, unlike SELECT FOR UPDATE on an absent row.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.payment:'.$event->payment_id]);
            $alias = DB::table('acquisition_event_ids')->where('event_id', $event->event_id)->first();
            if ($alias && $alias->payment_id !== $event->payment_id) {
                throw new \DomainException('ACQUISITION_EVENT_CONFLICT');
            }
            $previous = DB::table('acquisition_receipts')->where('payment_id', $event->payment_id)->first();
            if ($previous) {
                if ($production) {
                    throw new \LogicException('PRODUCTION_PURCHASE_RECEIPT_ALREADY_EXISTS');
                }
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new \DomainException('ACQUISITION_PAYMENT_CONFLICT');
                }
                if (! $alias) {
                    DB::table('acquisition_event_ids')->insert(['event_id' => $event->event_id, 'payment_id' => $event->payment_id]);
                }

                $this->behavior($event);

                return null;
            }
            $snapshot = $event->attribution_snapshot;
            $source = $snapshot ? DB::table('acquisition_sources')->where('id', $snapshot->source_id)->first() : null;
            $matched = $source && $snapshot->link_id === $source->link_id && $snapshot->channel === $source->channel
                && $snapshot->pixel_config_version === $source->pixel_version && $snapshot->consent === $source->consent
                && CarbonImmutable::parse($snapshot->captured_at)->equalTo(CarbonImmutable::parse($source->captured_at));
            $pixel = $matched ? DB::table('acquisition_pixel_versions')->where('pixel_id', $source->pixel_id)->where('version', $source->pixel_version)->first() : null;
            $matched = $matched && $pixel && $pixel->platform === $source->channel;
            DB::table('acquisition_receipts')->insert([
                'event_id' => $event->event_id, 'payment_id' => $event->payment_id, 'fingerprint' => $fingerprint,
                'link_id' => $matched ? $source->link_id : null, 'source_id' => $matched ? $source->id : null,
                'transaction_kind' => $event->transaction_kind->value, 'discount_applied' => $event->discount_applied,
                'amount_minor' => $event->amount_minor, 'currency' => $event->currency, 'is_test' => $event->is_test,
                'occurred_at' => CarbonImmutable::parse($event->occurred_at),
                'attribution_snapshot' => $snapshot ? json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR) : null,
                'source_status' => ! $snapshot ? 'unattributed' : ($matched ? 'matched' : 'unresolved_source'), 'received_at' => now(),
            ]);
            DB::table('acquisition_event_ids')->insert(['event_id' => $event->event_id, 'payment_id' => $event->payment_id]);
            if (! $production) {
                $this->behavior($event);
            }
            if ($matched) {
                $id = AcquisitionStore::id();
                DB::table('acquisition_deliveries')->insert([
                    'id' => $id, 'receipt_id' => $event->event_id, 'link_id' => $source->link_id,
                    'source_id' => $source->id, 'pixel_id' => $source->pixel_id, 'pixel_version' => $source->pixel_version,
                    'platform' => $pixel->platform, 'external_id' => $pixel->external_id, 'environment' => $pixel->environment,
                    'event_key' => 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$event->payment_id),
                    'state' => $production ? 'pilot_ready' : 'pending',
                    'next_attempt_at' => $production ? null : now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $id;
            }

            return null;
        }, 3);
    }

    private function behavior(PaymentSettled $event): void
    {
        if (TrackingContext::advertisingTestOnly() || ! app(TrackingContext::class)->enabled()) {
            return;
        }
        $result = app(BehaviorEventRecorder::class)->recordFact('payment_confirmed', [
            'payment_id' => $event->payment_id, 'order_id' => $event->order_id, 'subscription_id' => $event->subscription_id,
            'transaction_kind' => $event->transaction_kind->value, 'amount_minor' => $event->amount_minor,
            'currency' => $event->currency, 'is_test' => $event->is_test, 'occurred_at' => $event->occurred_at,
        ], 'payment:'.$event->payment_id);
        if ($result === BehaviorRecordingResult::Retry) {
            throw new \RuntimeException('ACQUISITION_ANALYTICS_RETRY');
        }
    }
}
