<?php

namespace App\Modules\Acquisition\Production;

use App\Data\PaymentSettled;
use App\Modules\Acquisition\Sending\Credentials;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Billing\Services\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class SinglePurchaseSender
{
    private const RESERVED = 'PRODUCTION_PURCHASE_RESERVED_NO_RETRY';

    public function __construct(private PurchaseEvidence $evidence) {}

    public function send(string $paymentId, string $orderId): array
    {
        if (! Str::isUuid($paymentId) || ! Str::isUuid($orderId) || DB::transactionLevel() !== 0) {
            return ['state' => 'blocked', 'reason' => 'PRODUCTION_PURCHASE_INPUT_INVALID'];
        }

        $scope = $this->evidence->scope();
        // Outbox owns the provider, account, ledger and notice checks. This callback does no transport.
        $outbox = app(OutboxService::class)->dispatchProductionPilotPayment($paymentId,
            function (PaymentSettled $event, array $approved) use ($paymentId, $orderId, $scope): bool {
                return $approved === $scope && $event->payment_id === $paymentId && $event->order_id === $orderId
                    && app(PaymentReceiver::class)->receiveProduction($event, $approved);
            });
        if (! in_array($outbox, ['delivered', 'already_delivered'], true)) {
            throw new LogicException('PRODUCTION_PURCHASE_OUTBOX_NOT_CONFIRMED');
        }

        $reservation = DB::transaction(function () use ($paymentId, $orderId, $scope) {
            $slot = DB::table('acquisition_production_purchase')->where('slot', 1)->lockForUpdate()->first();
            if (! $slot || $slot->payment_id !== $paymentId || $slot->order_id !== $orderId
                || ! hash_equals($slot->scope_digest, $this->evidence->digest($scope))) {
                throw new LogicException('PRODUCTION_PURCHASE_SLOT_MISMATCH');
            }
            $row = DB::table('acquisition_deliveries')->where('id', $slot->delivery_id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'pilot_ready' || (int) $row->attempts !== 0 || $row->first_attempted_at !== null
                || $row->accepted_at !== null || $row->lease_token !== null || $row->lease_until !== null
                || $row->next_attempt_at !== null || $row->payload_encrypted !== null) {
                return null;
            }
            $prepared = $this->prepare($row, $paymentId, $scope);
            // Terminal before I/O. A crash after this commit burns the only attempt.
            DB::table('acquisition_deliveries')->where('id', $row->id)->update([
                'state' => 'failed', 'last_error' => self::RESERVED, 'attempts' => 1,
                'first_attempted_at' => now(), 'updated_at' => now(),
            ]);

            return [$row->id, $prepared];
        });
        if ($reservation === null) {
            return ['state' => 'blocked', 'reason' => 'PRODUCTION_PURCHASE_NOT_FRESH'];
        }

        [$id, $prepared] = $reservation;
        $state = 'unconfirmed';
        $reason = 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY';
        try {
            // Billing also rechecks refunds, reversals and the original ledger on a delivered replay.
            $checked = app(OutboxService::class)->dispatchProductionPilotPayment($paymentId, static fn (): bool => false);
            $row = DB::table('acquisition_deliveries')->find($id);
            if ($checked !== 'already_delivered' || ! $row || $row->state !== 'failed' || $row->last_error !== self::RESERVED
                || (int) $row->attempts !== 1 || $row->payload_encrypted !== null
                || $this->prepare($row, $paymentId, $scope) !== $prepared) {
                throw new LogicException('PRODUCTION_PURCHASE_RESERVATION_CHANGED');
            }
        } catch (Throwable) {
            $state = 'blocked';
            $reason = 'PRODUCTION_PURCHASE_STOPPED_NO_RETRY';
        }
        if ($state === 'unconfirmed') {
            [$state, $reason] = $this->sendReserved($prepared);
        }

        $updated = DB::table('acquisition_deliveries')->where('id', $id)->where('state', 'failed')
            ->where('last_error', self::RESERVED)->where('attempts', 1)->update([
                'state' => $state === 'api_accepted' ? 'api_accepted' : 'failed', 'last_error' => $reason,
                'accepted_at' => $state === 'api_accepted' ? now() : null, 'updated_at' => now(),
            ]);

        return $updated === 1 ? ['state' => $state, 'reason' => $reason]
            : ['state' => 'unconfirmed', 'reason' => 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY'];
    }

    private function sendReserved(array $prepared): array
    {
        try {
            // This fixed host and connection are only reachable after the exact durable reservation.
            $response = Http::acceptJson()->asJson()->withToken($prepared['token'])->timeout(12)->connectTimeout(4)
                ->withoutRedirecting()->post($prepared['url'], $prepared['payload']);
            $body = $response->json();
            if ($response->successful() && is_array($body) && ($body['events_received'] ?? null) === 1 && ! isset($body['error'])) {
                return ['api_accepted', null];
            }
            if ($response->clientError() && $response->status() !== 429
                && is_array($body['error'] ?? null) && isset($body['error']['code'])
                && ($body['error']['is_transient'] ?? false) !== true) {
                return ['rejected', 'PRODUCTION_PURCHASE_REJECTED_NO_RETRY'];
            }
        } catch (Throwable) {
            // Preserve only the safe terminal outcome. Never expose HTTP exceptions or payloads.
        }

        return ['unconfirmed', 'PRODUCTION_PURCHASE_UNKNOWN_NO_RETRY'];
    }

    private function prepare(object $row, string $paymentId, array $scope): array
    {
        $payload = $this->evidence->prepare($row, $paymentId, $scope);
        $credential = app(Credentials::class)->forDelivery($row);
        $version = config('acquisition.meta_api_version');
        if (! $credential || array_key_exists('test_event_code', $credential) || ! is_string($version)
            || ! preg_match('/^v[0-9]{2}\\.0$/D', $version) || ! preg_match('/^[0-9]{5,30}$/D', $row->external_id)) {
            throw new LogicException('PRODUCTION_PURCHASE_META_CREDENTIALS_INVALID');
        }

        return ['payload' => $payload, 'token' => $credential['token'],
            'url' => 'https://graph.facebook.com/'.$version.'/'.$row->external_id.'/events'];
    }
}
