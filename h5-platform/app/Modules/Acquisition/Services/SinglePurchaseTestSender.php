<?php

namespace App\Modules\Acquisition\Services;

use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Sending\DeliveryGate;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\MinimalPayload;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/** Exact fresh test delivery only. Official Sandbox provenance is verified separately by 00/03. */
final class SinglePurchaseTestSender
{
    private const RESERVED = 'PURCHASE_TEST_RESERVED_NO_RETRY';

    private ?string $observingUrl = null;

    private bool $explicitRejection = false;

    public function __construct()
    {
        // Observe the existing adapter response without changing its transport or retaining response data.
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($this->observingUrl === null || $event->request->url() !== $this->observingUrl) {
                return;
            }
            $response = $event->response;
            if ($response->status() === 429 || $response->status() >= 500 || $response->status() < 200
                || ($response->status() >= 300 && $response->status() < 400) || strlen($response->body()) > 65536) {
                return;
            }
            $body = $response->json();
            $this->explicitRejection = is_array($body) && (
                (is_array($body['error'] ?? null) && isset($body['error']['code']))
                || (isset($body['code']) && is_int($body['code']) && $body['code'] !== 0));
        });
    }

    private function prepare(object $row): array
    {
        app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
        $receipt = DB::table('acquisition_receipts')->where('event_id', $row->receipt_id)->first();
        $source = DB::table('acquisition_sources')->where('id', $row->source_id)->first();
        $pixel = DB::table('acquisition_pixels')->where('id', $row->pixel_id)->first();
        $version = DB::table('acquisition_pixel_versions')->where('pixel_id', $row->pixel_id)->where('version', $row->pixel_version)->first();
        $link = DB::table('acquisition_links')->where('id', $row->link_id)->first();
        if (! $receipt || ! $source || ! $pixel || ! $version || ! $link || ! in_array($row->platform, ['meta', 'tiktok'], true)
            || ! $receipt->is_test || $row->environment !== 'test' || $source->is_simulation
            || ! in_array($receipt->transaction_kind, ['first_subscription', 'resubscription'], true)
            || $receipt->source_status !== 'matched' || $receipt->source_id !== $source->id || $receipt->link_id !== $row->link_id
            || $receipt->currency !== 'USD' || $receipt->amount_minor < 0
            || $row->event_key !== 'h5_'.hash('sha256', 'PaymentSettled.v1:'.$receipt->payment_id)) {
            return ['reason' => 'PURCHASE_TEST_FACT_INVALID'];
        }
        if (! $link->active || $link->pixel_id !== $row->pixel_id || $link->platform !== $row->platform
            || (int) $pixel->version !== (int) $row->pixel_version || $pixel->platform !== $row->platform
            || $pixel->environment !== 'test' || $pixel->external_id !== $row->external_id
            || $version->platform !== $row->platform || $version->environment !== 'test' || $version->external_id !== $row->external_id) {
            return ['reason' => 'PURCHASE_TEST_VERSION_CHANGED'];
        }
        $context = app(CheckoutContextStore::class)->forDelivery($row, $receipt, $source);
        if ($reason = app(DeliveryGate::class)->reason($row, $receipt, $source, $pixel, $context)) {
            return ['reason' => $reason];
        }
        if (config('acquisition.transport') !== 'http') {
            return ['reason' => 'EXTERNAL_DISABLED'];
        }
        $credential = app(Credentials::class)->forDelivery($row);
        if (! $credential || empty($credential['test_event_code'])) {
            return ['reason' => 'CREDENTIALS_REQUIRED'];
        }
        $adapter = $row->platform === 'meta' ? app(MetaAdapter::class) : app(TikTokAdapter::class);
        $payload = $adapter->payload($row, $receipt, $context, $credential['test_event_code']);
        if (! app(MinimalPayload::class)->valid($row, $receipt, $context, $payload, $credential['test_event_code'])) {
            return ['reason' => 'FROZEN_PAYLOAD_NOT_ALLOWED'];
        }

        return compact('payload', 'credential', 'adapter') + ['api_version' => config('acquisition.meta_api_version')];
    }

    public function send(string $id): array
    {
        try {
            app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
        } catch (Throwable) {
            return ['state' => 'blocked', 'reason' => 'STAGING_TEST_RUNTIME_INVALID'];
        }
        if (! Str::isUuid($id) || DB::transactionLevel() !== 0) {
            return ['state' => 'blocked', 'reason' => 'PURCHASE_TEST_INPUT_INVALID'];
        }
        try {
            $reservation = DB::transaction(function () use ($id) {
                $row = DB::table('acquisition_deliveries')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->state !== 'pending' || $row->attempts !== 0 || $row->payload_encrypted !== null
                    || $row->first_attempted_at !== null || $row->lease_token !== null || $row->lease_until !== null) {
                    return ['reason' => 'PURCHASE_TEST_NOT_FRESH'];
                }
                $prepared = $this->prepare($row);
                if (isset($prepared['reason'])) {
                    return $prepared;
                }
                $frozen = Crypt::encryptString(json_encode($prepared['payload'], JSON_THROW_ON_ERROR));
                DB::table('acquisition_deliveries')->where('id', $id)->update([
                    'state' => 'failed', 'last_error' => self::RESERVED, 'attempts' => 1, 'first_attempted_at' => now(),
                    'payload_encrypted' => $frozen, 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'updated_at' => now(),
                ]);

                return compact('row', 'prepared', 'frozen');
            });
        } catch (Throwable) {
            return ['state' => 'blocked', 'reason' => 'DELIVERY_CONTEXT_INVALID'];
        }
        if (isset($reservation['reason'])) {
            return ['state' => 'blocked', 'reason' => $reservation['reason']];
        }
        ['row' => $row, 'prepared' => $prepared, 'frozen' => $frozen] = $reservation;
        $result = ['state' => 'unconfirmed', 'reason' => 'PURCHASE_TEST_UNKNOWN_NO_RETRY'];
        try {
            $current = DB::table('acquisition_deliveries')->where('id', $id)->first();
            $check = $this->prepare($current);
            $reason = $check['reason'] ?? null;
            if (! $reason && ($check['credential'] !== $prepared['credential'] || $check['payload'] != $prepared['payload']
                || $check['api_version'] !== $prepared['api_version'])) {
                $reason = 'PURCHASE_TEST_VERSION_CHANGED';
            }
            if ($reason) {
                $result = ['state' => 'blocked', 'reason' => 'PURCHASE_TEST_STOP:'.$reason];
            } elseif ($current->state !== 'failed' || $current->last_error !== self::RESERVED || $current->attempts !== 1
                || $current->payload_encrypted !== $frozen) {
                $result = ['state' => 'blocked', 'reason' => 'PURCHASE_TEST_STOP:PURCHASE_TEST_NOT_FRESH'];
            } else {
                $this->explicitRejection = false;
                $this->observingUrl = $row->platform === 'meta'
                    ? 'https://graph.facebook.com/'.$prepared['api_version'].'/'.$row->external_id.'/events'
                    : 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
                $response = $prepared['adapter']->send($row, $prepared['payload'], $prepared['credential']['token']);
                $result = match (true) {
                    $response->state === 'api_accepted' => ['state' => 'api_accepted', 'reason' => null],
                    $response->state === 'blocked' => ['state' => 'blocked', 'reason' => 'PURCHASE_TEST_STOP:'.$response->error],
                    $this->explicitRejection => ['state' => 'rejected', 'reason' => 'PURCHASE_TEST_REJECTED_NO_RETRY'],
                    default => ['state' => 'unconfirmed', 'reason' => 'PURCHASE_TEST_UNKNOWN_NO_RETRY'],
                };
            }
        } catch (Throwable) {
            // An exception, 503 or missing/invalid response is never evidence that the platform did not process it.
        } finally {
            $this->observingUrl = null;
        }
        DB::table('acquisition_deliveries')->where('id', $id)->where('state', 'failed')->where('last_error', self::RESERVED)
            ->where('attempts', 1)->where('payload_encrypted', $frozen)->update([
                'state' => $result['state'] === 'api_accepted' ? 'api_accepted' : 'failed', 'last_error' => $result['reason'],
                'accepted_at' => $result['state'] === 'api_accepted' ? now() : null, 'updated_at' => now(),
            ]);

        return $result;
    }
}
