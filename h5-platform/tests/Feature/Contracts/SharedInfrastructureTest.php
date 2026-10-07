<?php

namespace Tests\Feature\Contracts;

use App\Contracts\RegionResolver;
use App\Support\LocalModuleRegistry;
use App\Support\ServerRegionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

class SharedInfrastructureTest extends TestCase
{
    public function test_runtime_contract_version_matches_the_published_openapi(): void
    {
        $spec = json_decode(file_get_contents(base_path('../docs/contracts/openapi.yaml')), true);
        $this->assertSame($spec['info']['version'], config('h5.contract'));
        $this->assertSame('1.0.4', config('h5.contract'));
    }

    public function test_absent_local_opt_in_preserves_the_missing_provider_and_unresolved_region(): void
    {
        $modules = ['content' => ['provider' => null, 'ready' => false]];
        $this->assertSame($modules, (new LocalModuleRegistry)->resolve($modules, '', 'local', config('h5.local_module_providers')));
        $this->assertInstanceOf(RegionResolver::class, app(RegionResolver::class));
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->region('127.0.0.1', 'local', false));
    }

    public function test_allowlisted_local_provider_can_load_without_a_root_config_edit(): void
    {
        $modules = config('h5.modules');
        $modules['content'] = ['provider' => null, 'ready' => false];
        $result = (new LocalModuleRegistry)->resolve($modules, ' content,content ', 'testing', ['content' => ContractFixtureProvider::class]);
        $this->assertSame(['provider' => ContractFixtureProvider::class, 'ready' => true], $result['content']);
        $this->assertSame($modules['identity'], $result['identity']);
        $this->app->register($result['content']['provider']);
        $this->assertSame('loaded-only-in-this-test', app('contract-fixture-module'));
    }

    public function test_local_loading_rejects_unknown_missing_invalid_and_nonlocal_providers(): void
    {
        $registry = new LocalModuleRegistry;
        $modules = config('h5.modules');
        foreach ([
            ['content', 'production', ['content' => ContractFixtureProvider::class]],
            ['content', 'staging', ['content' => ContractFixtureProvider::class]],
            ['billing', 'local', ['content' => ContractFixtureProvider::class]],
            ['App\\UntrustedProvider', 'local', ['content' => ContractFixtureProvider::class]],
            ['content', 'local', ['content' => 'MissingContentProvider']],
            ['content', 'local', ['content' => \stdClass::class]],
            // An accepted provider cannot be replaced by a local test provider.
            ['content', 'local', ['content' => ContractFixtureProvider::class]],
        ] as [$requested, $environment, $allowlist]) {
            try {
                $registry->resolve($modules, $requested, $environment, $allowlist);
                $this->fail('Unsafe local module loading must fail.');
            } catch (\LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame($modules, $registry->resolve($modules, '', 'production', []));
    }

    public function test_audience_and_acquisition_can_opt_in_without_replacing_other_modules(): void
    {
        foreach (['audience' => 'Audience', 'acquisition' => 'Acquisition'] as $name => $module) {
            $this->assertSame('App\\Modules\\'.$module.'\\'.$module.'ServiceProvider', config('h5.local_module_providers.'.$name));
            $modules = config('h5.modules');
            $modules[$name] = ['provider' => null, 'ready' => false];
            $registry = new LocalModuleRegistry;
            $this->assertSame($modules, $registry->resolve($modules, '', 'local', config('h5.local_module_providers')));
            foreach (['local', 'testing'] as $environment) {
                $result = $registry->resolve($modules, $name, $environment, [$name => ContractFixtureProvider::class]);
                $this->assertSame(['provider' => ContractFixtureProvider::class, 'ready' => true], $result[$name]);
                $this->assertSame(array_diff_key($modules, [$name => true]), array_diff_key($result, [$name => true]));
            }
            foreach ([['local', 'MissingFutureProvider'], ['production', ContractFixtureProvider::class], ['staging', ContractFixtureProvider::class]] as [$environment, $provider]) {
                try {
                    $registry->resolve($modules, $name, $environment, [$name => $provider]);
                    $this->fail('Future modules must not bypass missing-provider or environment checks.');
                } catch (\LogicException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
        }
    }

    private function region(string $peer, string $environment, bool $enabled, bool $console = false): array
    {
        $request = Request::create('/api/v1/catalog?region=US', 'GET', ['country_code' => 'US'], [], [], [
            'REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            'HTTP_CF_IPCOUNTRY' => 'US', 'HTTP_X_COUNTRY_CODE' => 'US',
        ]);

        return (new ServerRegionResolver($request, $environment, $enabled, $console))->resolve()->toArray();
    }

    public function test_explicit_us_fixture_is_limited_to_direct_loopback_http(): void
    {
        foreach (['127.0.0.1', '::1'] as $peer) {
            $this->assertSame(['country_code' => 'US', 'source' => 'local_us_test'], $this->region($peer, 'local', true));
        }
        foreach ([['127.0.0.1', false, false], ['203.0.113.10', true, false], ['127.0.0.1', true, true]] as [$peer, $enabled, $console]) {
            $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->region($peer, 'local', $enabled, $console));
        }
    }

    public function test_production_never_accepts_forged_country_headers_or_local_region_override(): void
    {
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->region('127.0.0.1', 'production', false));
        $this->expectException(\LogicException::class);
        $this->region('127.0.0.1', 'production', true);
    }
}

class ContractFixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('contract-fixture-module', 'loaded-only-in-this-test');
    }
}
