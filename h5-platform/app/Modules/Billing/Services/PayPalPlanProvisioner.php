<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateway\PayPal\SandboxApi;
use App\Modules\Billing\Gateway\PayPal\SandboxWeeklyPlan;
use App\Modules\Billing\Gateway\PayPal\WeeklyPlanVerifier;
use App\Modules\Billing\Gateway\ProviderScope;
use RuntimeException;

/** Internal sandbox provisioning, deliberately not exposed as an admin or public action. */
final class PayPalPlanProvisioner
{
    public function __construct(private SandboxApi $api, private ProviderOperationStore $operations) {}

    public function ensure(ProviderScope $scope, string $ownerId, SandboxWeeklyPlan $snapshot, string $productId): string
    {
        $stamp = $this->api->configurationStamp($scope);
        $intent = $this->operations->reserve($scope, 'create_plan', $ownerId, 1, $snapshot->createRequest($productId));
        if ($intent->external_id === null) {
            $intent = $this->operations->beginAttempt($scope, $intent->id);
            $reply = $this->api->createPlan($snapshot, $productId, $intent->request_key);
            $id = $reply['id'] ?? null;
            if (! is_string($id) || preg_match('/\AP-[A-Z0-9]{24}\z/D', $id) !== 1) {
                throw new RuntimeException('PAYPAL_PLAN_RECONCILIATION_REQUIRED');
            }
            // Preserve a returned identity even if the subsequent read/validation fails.
            $this->operations->complete($scope, $intent->id, $id);
        } else {
            $id = $intent->external_id;
        }
        $this->sameConfiguration($scope, $stamp);
        $actual = $this->api->plan($id);
        $this->sameConfiguration($scope, $stamp);
        (new WeeklyPlanVerifier)->assertMatches($actual, $id, $snapshot, $productId);
        $this->operations->link($scope, 'plan', $id, 'plan', $ownerId);

        return $id;
    }

    private function sameConfiguration(ProviderScope $scope, string $stamp): void
    {
        if (! hash_equals($stamp, $this->api->configurationStamp($scope))) {
            throw new RuntimeException('PAYPAL_CONFIGURATION_CHANGED');
        }
    }
}
