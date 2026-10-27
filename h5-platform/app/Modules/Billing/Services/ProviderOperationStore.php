<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\ProviderScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Durable external intent and resource identity; never writes paid facts or memberships. */
final class ProviderOperationStore
{
    public function reserve(ProviderScope $scope, string $operation, string $ownerId, int $version, array $payload): object
    {
        if ($scope->provider !== 'paypal' || ! in_array($operation, ['create_plan', 'create_subscription'], true)
            || ! Str::isUuid($ownerId) || $version < 1) {
            throw new InvalidArgumentException('BILLING_PROVIDER_INTENT_INVALID');
        }
        $canonical = $this->canonical($payload);
        $fingerprint = hash('sha256', $canonical);
        $identity = $scope->columns() + ['operation' => $operation, 'owner_id' => $ownerId, 'operation_version' => $version];

        return DB::transaction(function () use ($identity, $canonical, $fingerprint) {
            DB::table('billing_provider_intents')->insertOrIgnore($identity + [
                'id' => BillingStore::id(), 'request_key' => BillingStore::id(), 'fingerprint' => $fingerprint,
                'request_payload' => $canonical, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $intent = DB::table('billing_provider_intents')->where($identity)->lockForUpdate()->first();
            if (! $intent || ! hash_equals($intent->fingerprint, $fingerprint)) {
                throw new RuntimeException('BILLING_PROVIDER_INTENT_CONFLICT');
            }

            return $intent;
        });
    }

    /** Persist unknown BEFORE network dispatch. Call only after this transaction commits. */
    public function beginAttempt(ProviderScope $scope, string $intentId): object
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('BILLING_PROVIDER_INTENT_NOT_COMMITTED');
        }

        return DB::transaction(function () use ($scope, $intentId) {
            $query = DB::table('billing_provider_intents')->where($scope->columns())->where('id', $intentId);
            $intent = (clone $query)->lockForUpdate()->first();
            if (! $intent || ! in_array($intent->state, ['prepared', 'unknown'], true)) {
                throw new RuntimeException('BILLING_PROVIDER_INTENT_NOT_SENDABLE');
            }
            if ($intent->retry_before !== null && now()->gte(CarbonImmutable::parse($intent->retry_before))) {
                throw new RuntimeException('BILLING_PROVIDER_INTENT_RECONCILE_ONLY');
            }
            $query->update(['state' => 'unknown', 'first_attempted_at' => $intent->first_attempted_at ?? now(),
                // Local conservative boundary starts BEFORE PayPal receives the first request.
                'retry_before' => $intent->retry_before ?? now()->addHours(72), 'updated_at' => now()]);

            return $query->first();
        });
    }

    public function complete(ProviderScope $scope, string $intentId, string $externalId): void
    {
        $this->externalId($externalId);
        DB::transaction(function () use ($scope, $intentId, $externalId) {
            $query = DB::table('billing_provider_intents')->where($scope->columns())->where('id', $intentId);
            $intent = (clone $query)->lockForUpdate()->first();
            if (! $intent || ! in_array($intent->state, ['unknown', 'succeeded'], true)
                || ($intent->external_id !== null && $intent->external_id !== $externalId)) {
                throw new RuntimeException('BILLING_PROVIDER_INTENT_CONFLICT');
            }
            $query->update(['state' => 'succeeded', 'external_id' => $externalId, 'updated_at' => now()]);
        });
    }

    public function link(ProviderScope $scope, string $resourceType, string $externalId, string $ownerType, string $ownerId): object
    {
        $this->externalId($externalId);
        $valid = ['subscription' => 'subscription', 'plan' => 'plan', 'sale' => 'payment', 'refund' => 'refund', 'event' => 'inbox'];
        if (($valid[$resourceType] ?? null) !== $ownerType || ! Str::isUuid($ownerId)) {
            throw new InvalidArgumentException('BILLING_PROVIDER_RESOURCE_INVALID');
        }
        $identity = $scope->columns() + ['resource_type' => $resourceType, 'external_id' => $externalId];

        return DB::transaction(function () use ($identity, $ownerType, $ownerId) {
            DB::table('billing_provider_resources')->insertOrIgnore($identity + [
                'id' => BillingStore::id(), 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'created_at' => now(),
            ]);
            $link = DB::table('billing_provider_resources')->where($identity)->lockForUpdate()->first();
            if (! $link || $link->owner_type !== $ownerType || $link->owner_id !== $ownerId) {
                throw new RuntimeException('BILLING_PROVIDER_RESOURCE_CONFLICT');
            }

            return $link;
        });
    }

    private function externalId(string $id): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('BILLING_PROVIDER_RESOURCE_INVALID');
        }
    }

    private function canonical(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($normalize, $value);
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('BILLING_PROVIDER_PAYLOAD_INVALID');
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
