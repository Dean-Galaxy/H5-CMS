<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Billing\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcileBilling extends Command
{
    protected $signature = 'billing:reconcile {--limit=100 : Maximum objects per reconciliation category}';

    protected $description = 'Reconcile trusted Stripe facts, pending operations, and durable billing inbox/outbox.';

    public function handle(PaymentGateway $gateway, SettlementService $settlement, BillingEngine $billing, WebhookService $inbox, OutboxService $outbox): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $errors = 0;
        $run = function (callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (Throwable) {
                $errors++;
            }
        };
        $run(fn () => $inbox->processPending($limit));
        $run(fn () => $billing->retryPendingOperations());
        foreach (DB::table('billing_orders')->whereNotNull('checkout_id')->whereIn('state', ['pending', 'processing', 'failed', 'review_required'])->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('updated_at')->limit($limit)->get() as $order) {
            $run(fn () => $settlement->syncCheckout($order->id));
            DB::table('billing_orders')->where('id', $order->id)->update(['last_reconciled_at' => now()]);
        }
        foreach (DB::table('billing_subscriptions')->whereNotNull('provider_id')->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('updated_at')->limit($limit)->get() as $sub) {
            $run(fn () => $settlement->syncSubscription($sub->provider_id));
            $run(function () use ($gateway, $sub, $settlement, $run) {
                foreach ($gateway->invoices($sub->provider_id) as $invoiceId) {
                    $run(fn () => $settlement->settleInvoice($invoiceId));
                }
            });
            DB::table('billing_subscriptions')->where('id', $sub->id)->update(['last_reconciled_at' => now()]);
        }
        foreach (DB::table('billing_payments')->whereNotNull('provider_charge_id')->orderByRaw('last_reconciled_at ASC NULLS FIRST')->orderBy('created_at')->limit($limit)->get() as $payment) {
            $run(fn () => $settlement->syncRefunds($payment->provider_charge_id));
            DB::table('billing_payments')->where('id', $payment->id)->update(['last_reconciled_at' => now()]);
        }
        $sent = $outbox->dispatchPending($limit);
        $this->line('Billing reconciliation finished; unresolved checks: '.$errors.'; events delivered: '.$sent.'.');

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
