<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Gateway\StripeGateway;
use App\Modules\Billing\Support\BillingRuntimePolicy;
use Illuminate\Console\Command;
use Throwable;

/** Offline diagnostic: never resolve BillingEngine or inspect account/payment data. */
final class BillingConfigurationCheck extends Command
{
    protected $signature = 'billing:check-config';

    protected $description = 'Report safe payment configuration readiness without contacting Stripe.';

    public function handle(BillingRuntimePolicy $policy): int
    {
        $result = [
            'configuration_valid' => false,
            'payment_configuration_ready' => false,
            'missing' => [],
            'diagnostic_code' => null,
            'official_verification' => 'not_performed',
            'network_performed' => false,
        ];
        try {
            $policy->assertSafeConfiguration();
            if (config('billing.driver') === 'stripe_test') {
                try {
                    (new StripeGateway)->assertSafeClientConfiguration();
                } catch (Throwable) {
                    $result['diagnostic_code'] = 'BILLING_CLIENT_CONFIGURATION_INVALID';

                    return $this->report($result);
                }
            }
            $result['configuration_valid'] = true;
            if (config('billing.driver') !== 'stripe_test') {
                $result['missing'][] = 'stripe_test_driver';
            }
            foreach (['stripe_secret', 'stripe_webhook_secret'] as $key) {
                if (in_array(config('billing.'.$key), [null, ''], true)) {
                    $result['missing'][] = $key;
                }
            }
            if ($result['missing'] === []) {
                $policy->assertAvailable();
                $result['payment_configuration_ready'] = true;
            } else {
                $result['diagnostic_code'] = 'BILLING_NOT_CONFIGURED';
            }
        } catch (Throwable) {
            // Fixed output only: exception text may contain configuration or provider details.
            $result['configuration_valid'] = false;
            $result['diagnostic_code'] = 'BILLING_CONFIGURATION_INVALID';
        }

        return $this->report($result);
    }

    private function report(array $result): int
    {
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['payment_configuration_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
