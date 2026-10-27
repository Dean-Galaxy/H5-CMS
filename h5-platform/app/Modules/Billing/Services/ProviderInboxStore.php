<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\ProviderScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Call only after provider verification. Persist minimal references, never signatures or raw bodies. */
final class ProviderInboxStore
{
    public function receive(ProviderScope $scope, array $event): string
    {
        if (($event['is_test'] ?? null) !== true) {
            throw new RuntimeException('LIVE_PAYMENTS_DISABLED');
        }
        $identity = $scope->columns() + ['provider_event_id' => $event['id']];

        return DB::transaction(function () use ($identity, $event) {
            DB::table('billing_inbox')->insertOrIgnore($identity + [
                'id' => BillingStore::id(), 'event_type' => $event['type'], 'object_id' => $event['object_id'],
                'related_object_id' => $event['related_object_id'] ?? null, 'is_test' => true,
                'occurred_at' => $event['created'], 'created_at' => now(),
            ]);
            $row = DB::table('billing_inbox')->where($identity)->lockForUpdate()->first();
            if (! $row || $row->event_type !== $event['type'] || $row->object_id !== $event['object_id']
                || $row->related_object_id !== ($event['related_object_id'] ?? null)
                || BillingStore::iso($row->occurred_at) !== BillingStore::iso($event['created'])) {
                throw new RuntimeException('BILLING_PROVIDER_EVENT_CONFLICT');
            }

            return $row->id;
        });
    }
}
