<?php

namespace App\Modules\Acquisition\Services;

use App\Data\PaymentSettled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PaymentReceiver
{
    /** Internal listener only. Persist then return; never contact ad platforms in Billing's transaction. */
    public function handle(PaymentSettled $event): void
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
        ])->validate();
        $fingerprintData = $data;
        unset($fingerprintData['event_id']);
        $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($event, $fingerprint) {
            // Serializes even before a row exists, unlike SELECT FOR UPDATE on an absent row.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.payment:'.$event->payment_id]);
            $alias = DB::table('acquisition_event_ids')->where('event_id', $event->event_id)->first();
            if ($alias && $alias->payment_id !== $event->payment_id) {
                throw new \DomainException('ACQUISITION_EVENT_CONFLICT');
            }
            $previous = DB::table('acquisition_receipts')->where('payment_id', $event->payment_id)->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new \DomainException('ACQUISITION_PAYMENT_CONFLICT');
                }
                if (! $alias) {
                    DB::table('acquisition_event_ids')->insert(['event_id' => $event->event_id, 'payment_id' => $event->payment_id]);
                }

                return;
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
            if ($matched) {
                DB::table('acquisition_deliveries')->insert([
                    'id' => AcquisitionStore::id(), 'receipt_id' => $event->event_id, 'link_id' => $source->link_id,
                    'source_id' => $source->id, 'pixel_id' => $source->pixel_id, 'pixel_version' => $source->pixel_version,
                    'platform' => $pixel->platform, 'external_id' => $pixel->external_id, 'environment' => $pixel->environment,
                    'event_key' => 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$event->payment_id),
                    'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);
    }
}
