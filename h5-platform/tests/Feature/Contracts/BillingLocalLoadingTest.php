<?php

namespace Tests\Feature\Contracts;

use App\Contracts\MembershipReader;
use App\Support\LocalModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tests\TestCase;

class BillingLocalLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_allowlist_does_not_enable_an_unaccepted_module(): void
    {
        $this->assertSame('App\\Modules\\Billing\\BillingServiceProvider', config('h5.local_module_providers.billing'));
        $modules = config('h5.modules');
        $modules['billing'] = ['provider' => null, 'ready' => false];
        $this->assertSame($modules, (new LocalModuleRegistry)->resolve($modules, '', 'local', config('h5.local_module_providers')));
    }

    public function test_explicit_local_loading_only_changes_billing_and_never_grants_fixture_entitlements(): void
    {
        $modules = config('h5.modules');
        $modules['billing'] = ['provider' => null, 'ready' => false];
        $originalMembershipProvider = get_class(app(MembershipReader::class));
        $registry = new LocalModuleRegistry;
        foreach (['local', 'testing'] as $environment) {
            $result = $registry->resolve($modules, ' billing,billing ', $environment, ['billing' => BillingLoadingFixture::class]);
            $this->assertSame(['provider' => BillingLoadingFixture::class, 'ready' => true], $result['billing']);
            $this->assertSame(array_diff_key($modules, ['billing' => true]), array_diff_key($result, ['billing' => true]));
        }
        $this->app->register($result['billing']['provider']);
        $this->assertTrue(app('billing-loading-fixture'));
        $this->assertInstanceOf($originalMembershipProvider, app(MembershipReader::class));
    }

    public function test_billing_opt_in_rejects_nonlocal_missing_invalid_and_replacement_providers(): void
    {
        $modules = config('h5.modules');
        $replacement = $modules;
        $replacement['billing'] = ['provider' => 'AcceptedBillingProvider', 'ready' => true];
        foreach ([
            [$modules, 'production', BillingLoadingFixture::class],
            [$modules, 'staging', BillingLoadingFixture::class],
            [$modules, 'local', 'MissingBillingProvider'],
            [$modules, 'testing', \stdClass::class],
            [$replacement, 'local', BillingLoadingFixture::class],
        ] as [$configured, $environment, $provider]) {
            try {
                (new LocalModuleRegistry)->resolve($configured, 'billing', $environment, ['billing' => $provider]);
                $this->fail('Unsafe Billing local loading must fail.');
            } catch (LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame($modules, (new LocalModuleRegistry)->resolve($modules, '', 'production', config('h5.local_module_providers')));
    }
}

// Only a loading marker. No Billing implementation or successful payment fixture.
class BillingLoadingFixture extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('billing-loading-fixture', true);
    }
}
