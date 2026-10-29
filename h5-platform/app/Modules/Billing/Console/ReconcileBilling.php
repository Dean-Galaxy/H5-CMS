<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\ProviderGatewayRouter;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\PayPalReconciliation;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Billing\Services\WebhookService;
use App\Modules\Billing\Support\ProviderActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcileBilling extends Command
{
    protected $signature = 'billing:reconcile {--limit=100 : Maximum objects per reconciliation category}';

    protected $description = 'Reconcile trusted provider facts, pending operations, and durable billing inbox/outbox.';

    public function handle(PaymentGateway $gateway, SettlementService $settlement, BillingEngine $billing, WebhookService $inbox, OutboxService $outbox): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $errors = 0;
        $router = new ProviderGatewayRouter($gateway);
        $run = function (callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (Throwable) {
                $errors++;
            }
        };
        $run(fn () => $inbox->processPending($limit));
        $run(fn () => $billing->retryPendingOperations());
        foreach (ProviderActivity::eligible(DB::table('billing_orders'))->whereNotNull('checkout_id')->whereIn('state', ['pending', 'processing', 'failed', 'review_required'])->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('updated_at')->limit($limit)->get() as $order) {
            $run(function () use ($router, $order, $settlement) {
                if ($order->provider === 'paypal') {
                    app(PayPalReconciliation::class)->sync($order->subscription_id);

                    return;
                }
                $router->forRecord($order);
                $settlement->syncCheckout($order->id);
            });
            DB::table('billing_orders')->where('id', $order->id)->update(['last_reconciled_at' => now()]);
        }
        foreach (ProviderActivity::eligible(DB::table('billing_subscriptions'))->whereNotNull('provider_id')->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('updated_at')->limit($limit)->get() as $sub) {
            $run(function () use ($router, $sub, $settlement) {
                if ($sub->provider === 'paypal') {
                    app(PayPalReconciliation::class)->sync($sub->id);

                    return;
                }
                $router->forRecord($sub);
                $settlement->syncSubscription($sub->provider_id);
            });
            $run(function () use ($router, $sub, $settlement, $run) {
                if ($sub->provider === 'paypal') {
                    return;
                }
                foreach ($router->forRecord($sub)->invoices($sub->provider_id) as $invoiceId) {
                    $run(fn () => $settlement->settleInvoice($invoiceId));
                }
            });
            DB::table('billing_subscriptions')->where('id', $sub->id)->update(['last_reconciled_at' => now()]);
        }
        foreach (ProviderActivity::eligible(DB::table('billing_payments'))->whereNotNull('provider_charge_id')->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('created_at')->limit($limit)->get() as $payment) {
            $run(function () use ($router, $payment, $settlement) {
                if ($payment->provider === 'paypal') {
                    app(PayPalReconciliation::class)->syncRefunds($payment->id);

                    return;
                }
                $router->forRecord($payment);
                $settlement->syncRefunds($payment->provider_charge_id);
            });
            DB::table('billing_payments')->where('id', $payment->id)->update(['last_reconciled_at' => now()]);
        }
        $run(fn () => $billing->retryPendingOperations());
        $sent = $outbox->dispatchPending($limit);
        $this->line('Billing reconciliation finished; unresolved checks: '.$errors.'; events delivered: '.$sent.'.');

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
