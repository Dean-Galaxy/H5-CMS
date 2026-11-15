<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\PayPal\PayPalReadFailure;
use App\Modules\Billing\Gateway\PayPal\SandboxWebhookEnvelope;
use App\Modules\Billing\Gateway\ProviderGatewayRouter;
use App\Modules\Billing\Gateway\ProviderScope;
use App\Modules\Billing\Support\ProviderActivity;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WebhookService
{
    public function __construct(private PaymentGateway $gateway, private SettlementService $settlement) {}

    public function receive(string $payload, string $signature): void
    {
        $event = $this->gateway->verifyEvent($payload, $signature);
        app(ProviderInboxStore::class)->receive(ProviderScope::legacyStripe(), $event);
    }

    public function processPending(int $limit = 100): int
    {
        $done = 0;
        $ids = ProviderActivity::eligible(DB::table('billing_inbox'))->where(function ($q) {
            $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'processing')->where('processed_at', '<', now()->subMinutes(5)));
        })->where(fn ($q) => $q->whereNull('paypal_retry_at')->orWhere('paypal_retry_at', '<=', now()))
            ->orderByRaw('last_attempted_at ASC NULLS FIRST')->orderBy('created_at')->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id) {
                $event = DB::table('billing_inbox')->where('id', $id)->lockForUpdate()->first();
                if (! $event || ($event->paypal_retry_at !== null && now()->lt($event->paypal_retry_at)) || ProviderActivity::disabled($event->provider, $event->provider_environment) || $event->state === 'processed' || $event->state === 'ignored'
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
                DB::table('billing_inbox')->where('id', $id)->update(['state' => $supported ? 'processed' : 'ignored', 'last_error' => null, 'processed_at' => now(), 'paypal_retry_at' => null]);
                $done++;
            } catch (Throwable $error) {
                // Persist a safe code, never Stripe response bodies, signatures or payment data.
                $retryAt = $error instanceof PayPalReadFailure ? $error->retryAt : null;
                $diagnostic = match ($error instanceof PayPalReadFailure ? $error->reason : null) {
                    'SUBSCRIPTION_NOT_FOUND' => 'PAYPAL_SUBSCRIPTION_NOT_FOUND',
                    'SUBSCRIPTION_NOT_FOUND_PRIORITY' => 'PAYPAL_SUBSCRIPTION_NOT_FOUND_PRIORITY',
                    default => 'RECONCILIATION_REQUIRED',
                };
                DB::table('billing_inbox')->where('id', $id)->update(['state' => 'pending', 'last_error' => $diagnostic,
                    'processed_at' => null, 'paypal_retry_at' => $retryAt]);
            }
        }

        return $done;
    }

    private function process(object $event): bool
    {
        if ((bool) $event->is_test !== ProviderScope::fromRecord($event)->isTest()) {
            throw new RuntimeException('LIVE_PAYMENTS_DISABLED');
        }
        if ($event->provider === 'paypal') {
            return SandboxWebhookEnvelope::classify($event->event_type) !== 'ignore'
                ? app(PayPalReconciliation::class)->process($event) : false;
        }
        (new ProviderGatewayRouter($this->gateway))->forRecord($event);
        if (str_starts_with($event->event_type, 'invoice.')) {
            $this->settlement->settleInvoice($event->object_id);
        } elseif (str_starts_with($event->event_type, 'customer.subscription.')) {
            $this->settlement->syncSubscription($event->object_id);
        } elseif (str_starts_with($event->event_type, 'checkout.session.')) {
            $order = DB::table('billing_orders')->where(ProviderScope::fromRecord($event)->columns())->where('checkout_id', $event->object_id)->first();
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
