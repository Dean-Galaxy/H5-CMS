<?php

namespace Tests\Feature\Contracts;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\MembershipReader;
use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcceptedBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_billing_loads_without_opt_in_and_does_not_enable_external_payments_or_other_modules(): void
    {
        $this->assertSame('', config('h5.local_modules'));
        $this->assertSame(['provider' => BillingServiceProvider::class, 'ready' => true], config('h5.modules.billing'));
        $this->assertInstanceOf(BillingEngine::class, app(BillingService::class));
        $this->assertInstanceOf(BillingEngine::class, app(MembershipReader::class));
        $this->assertInstanceOf(UnconfiguredGateway::class, app(PaymentGateway::class));
        $this->assertFalse(app(AttributionService::class)->enabled());
        $this->getJson('/health')->assertOk()
            ->assertJsonPath('modules.identity', 'ready')->assertJsonPath('modules.content', 'ready')
            ->assertJsonPath('modules.billing', 'ready')->assertJsonPath('modules.audience', 'not_ready')
            ->assertJsonPath('modules.acquisition', 'not_ready');

        $commands = array_map(fn ($event) => $event->command, app(Schedule::class)->events());
        foreach (['billing:reconcile', 'content:publish-due', 'content:process-media'] as $command) {
            $this->assertCount(1, array_filter($commands, fn ($value) => str_contains($value, $command)));
        }
    }

    public function test_real_nonmember_and_configured_price_remain_truthful_when_stripe_is_unconfigured(): void
    {
        // A fresh workspace has no active price and does not fabricate one.
        $this->getJson('/api/v1/billing/offer')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1,
            'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $viewer = Viewer::forceCreate(['name' => 'Reception viewer', 'email' => 'reception@example.test',
            'password' => 'TestOnlyPassword1234', 'email_verified_at' => now()]);
        $this->assertTrue($viewer->hasVerifiedEmail());
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        $this->getJson('/api/v1/me/membership')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('access_active', false)->assertJsonPath('access_from', null)
            ->assertJsonPath('access_until', null)->assertJsonPath('subscription_state', 'none')
            ->assertJsonPath('payment_resolution_required', false);
        $offer = $this->getJson('/api/v1/billing/offer')->assertOk()
            ->assertJsonPath('regular_amount_minor', 1200)->assertJsonPath('first_week_amount_minor', 300)
            ->assertJsonPath('eligible_for_first_offer', true)->json();
        $this->app->instance(RegionResolver::class, new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
        $this->postJson('/api/v1/billing/checkout', [
            'offer_id' => $offer['offer_id'], 'price_version' => $offer['price_version'], 'return_to' => '/membership',
        ], ['Idempotency-Key' => 'reception-unconfigured-checkout'])
            ->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_NOT_READY');
        foreach (['billing_orders', 'billing_subscriptions', 'billing_payments', 'billing_entitlements', 'billing_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
