<?php

namespace App\Modules\Billing\Services;

use App\Data\PaymentSettled;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OutboxService
{
    public function __construct(private Dispatcher $events) {}

    public function dispatchPending(int $limit = 100): int
    {
        $sent = 0;
        $ids = DB::table('billing_outbox')->whereNull('delivered_at')->orderByRaw('last_attempted_at ASC NULLS FIRST')->orderBy('created_at')->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $delivered = DB::transaction(function () use ($id) {
                    $row = DB::table('billing_outbox')->where('id', $id)->lockForUpdate()->first();
                    if (! $row || $row->delivered_at) {
                        return false;
                    }
                    if (! $this->events->hasListeners(PaymentSettled::class)) {
                        DB::table('billing_outbox')->where('id', $id)->update(['last_error' => 'CONSUMER_NOT_READY', 'last_attempted_at' => now()]);

                        return false;
                    }
                    $event = PaymentSettled::fromArray(BillingStore::json($row->payload));
                    $responses = $this->events->dispatch($event);
                    if (is_array($responses) && in_array(false, $responses, true)) {
                        throw new \RuntimeException('CONSUMER_REJECTED');
                    }
                    DB::table('billing_outbox')->where('id', $id)->update([
                        'delivered_at' => now(), 'last_attempted_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'last_error' => null,
                    ]);

                    return true;
                });
                if ($delivered) {
                    $sent++;
                }
            } catch (Throwable) {
                DB::table('billing_outbox')->where('id', $id)->whereNull('delivered_at')->update([
                    'attempts' => DB::raw('attempts + 1'), 'last_error' => 'CONSUMER_RETRY_REQUIRED', 'last_attempted_at' => now(),
                ]);
            }
        }

        return $sent;
    }
}
