<?php

namespace App\Modules\Billing\Console;

use App\Contracts\AuditRecorder;
use App\Modules\Billing\Services\BillingStore;
use App\Support\Admin\UsdAmount;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Server operator configuration only. No network or implicit sample production price. */
final class PayPalLivePrice extends Command
{
    protected $signature = 'billing:paypal-live-price {--expected-version= : Current global price version} {--regular-usd= : Explicit weekly USD price} {--first-week-usd= : Explicit first-week USD price} {--confirm-live-price : Confirm these are actual purchase prices}';

    protected $description = 'Append an explicitly confirmed production price; never enables purchasing.';

    public function handle(): int
    {
        try {
            app(RuntimeEnvironmentGuard::class)->assertSafeRuntime();
            if (! app()->environment('production') || ! $this->option('confirm-live-price')) {
                throw new RuntimeException;
            }
            $version = $this->option('expected-version');
            if (! is_string($version) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $version) !== 1) {
                throw new RuntimeException;
            }
            $regular = UsdAmount::fromDecimal($this->option('regular-usd'));
            $first = UsdAmount::fromDecimal($this->option('first-week-usd'));
            if ($regular < 1 || $regular > 99999999 || $first < 1 || $first > $regular) {
                throw new RuntimeException;
            }
            $created = DB::transaction(function () use ($version, $regular, $first) {
                DB::select('SELECT pg_advisory_xact_lock(30420301)');
                if ((int) (DB::table('billing_prices')->max('version') ?? 0) !== (int) $version) {
                    throw new RuntimeException;
                }
                $id = BillingStore::id();
                DB::table('billing_prices')->insert(['id' => $id, 'version' => (int) $version + 1,
                    'regular_amount_minor' => $regular, 'first_week_amount_minor' => $first,
                    'currency' => 'USD', 'is_test' => false, 'created_at' => now()]);
                app(AuditRecorder::class)->record('billing.price.created', 'billing_price', $id, 'success', ['version' => (int) $version + 1]);

                return (int) $version + 1;
            });
            $this->line(json_encode(['price_version' => $created, 'currency' => 'USD', 'is_test' => false, 'purchase_configuration_changed' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('PAYPAL_LIVE_PRICE_NOT_SAVED');

            return self::FAILURE;
        }
    }
}
