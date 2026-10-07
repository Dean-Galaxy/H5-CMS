<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PaymentGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WebhookService
{
    public function __construct(private PaymentGateway $gateway, private SettlementService $settlement) {}

    public function receive(string $payload, string $signature): void
    {
        $event = $this->gateway->verifyEvent($payload, $signature);
        DB::table('billing_inbox')->insertOrIgnore([
            'id' => BillingStore::id(), 'provider_event_id' => $event['id'], 'event_type' => $event['type'],
            'object_id' => $event['object_id'], 'related_object_id' => $event['related_object_id'] ?? null,
            'is_test' => $event['is_test'], 'occurred_at' => $event['created'], 'created_at' => now(),
        ]);
    }

    public function processPending(int $limit = 100): int
    {
        $done = 0;
        $ids = DB::table('billing_inbox')->where(function ($q) {
            $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'processing')->where('processed_at', '<', now()->subMinutes(5)));
        })->orderByRaw('last_attempted_at ASC NULLS FIRST')->orderBy('created_at')->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id) {
                $event = DB::table('billing_inbox')->where('id', $id)->lockForUpdate()->first();
                if (! $event || $event->state === 'processed' || $event->state === 'ignored'
                    || ($event->state === 'processing' && $event->processed_at > now()->subMinutes(5)->toDateTimeString())) {
                    return null;
                }
                DB::table('billing_inbox')->where('id', $id)->update(['state' => 'processing', 'processed_at' => now(), 'last_attempted_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

                return $event;
            });
            if (! $event) {
                continue;
            }
            try {
                $supported = $this->process($event);
                DB::table('billing_inbox')->where('id', $id)->update(['state' => $supported ? 'processed' : 'ignored', 'last_error' => null, 'processed_at' => now()]);
                $done++;
            } catch (Throwable) {
                // Persist a safe code, never Stripe response bodies, signatures or payment data.
                DB::table('billing_inbox')->where('id', $id)->update(['state' => 'pending', 'last_error' => 'RECONCILIATION_REQUIRED', 'processed_at' => null]);
            }
        }

        return $done;
    }

    private function process(object $event): bool
    {
        if (! $event->is_test) {
            throw new RuntimeException('LIVE_PAYMENTS_DISABLED');
        }
        if (str_starts_with($event->event_type, 'invoice.')) {
            $this->settlement->settleInvoice($event->object_id);
        } elseif (str_starts_with($event->event_type, 'customer.subscription.')) {
            $this->settlement->syncSubscription($event->object_id);
        } elseif (str_starts_with($event->event_type, 'checkout.session.')) {
            $order = DB::table('billing_orders')->where('checkout_id', $event->object_id)->first();
            if (! $order) {
                throw new RuntimeException('BILLING_ORDER_NOT_FOUND');
            }
            $this->settlement->syncCheckout($order->id);
        } elseif ($event->event_type === 'charge.refunded') {
            $this->settlement->syncRefunds($event->object_id);
        } elseif (str_starts_with($event->event_type, 'refund.')) {
            if (! $event->related_object_id) {
                throw new RuntimeException('BILLING_REFUND_CHARGE_UNKNOWN');
            }
            $this->settlement->syncRefunds($event->related_object_id);
        } else {
            return false;
        }

        return true;
    }
}
