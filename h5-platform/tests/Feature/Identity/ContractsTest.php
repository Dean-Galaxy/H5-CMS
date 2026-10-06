<?php

namespace Tests\Feature\Identity;

use App\Contracts\AttributionService;
use App\Contracts\BillingService;
use App\Contracts\CatalogReader;
use App\Modules\Identity\Services\SafeReturn;
use App\Support\DependencyNotReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_samples_roundtrip_through_typed_php_consumers(): void
    {
        $dir = base_path('../docs/contracts/examples');
        foreach (json_decode(file_get_contents($dir.'/manifest.json'), true) as $example) {
            $class = 'App\\Data\\'.$example['schema'];
            if (! $example['valid'] || ! class_exists($class)) {
                continue;
            }
            $value = json_decode(file_get_contents($dir.'/'.$example['file']), true);
            $this->assertSame($value, $class::fromArray($value)->toArray());
        }
    }

    public function test_missing_provider_never_fabricates_business_success(): void
    {
        $this->assertFalse(app(AttributionService::class)->enabled());
        $this->assertNull(app(AttributionService::class)->snapshot('forged-marker'));
        foreach ([fn () => app(BillingService::class)->offer(null), fn () => app(CatalogReader::class)->catalog()] as $call) {
            try {
                $call();
                $this->fail('Should be unavailable');
            } catch (DependencyNotReady $e) {
                $this->assertSame(503, $e->status);
            }
        }
    }

    public function test_controlled_return_rejects_open_redirect_encodings(): void
    {
        $safe = app(SafeReturn::class);
        foreach (['https://evil.test', '//evil.test', '/%2f%2fevil.test', '/\\evil.test', '/%255c%255cevil.test', "/watch/a\r\nX: y", '/admin', '/api/v1/auth/logout', '/watch/../admin'] as $bad) {
            $this->assertSame('/identity/account', $safe->path($bad));
        }
        $this->assertSame('/watch/abc?position=90', $safe->path('/watch/abc?position=90'));
    }

    public function test_openapi_routes_and_enums_match_runtime(): void
    {
        $spec = json_decode(file_get_contents(base_path('../docs/contracts/openapi.yaml')), true);
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            $path = '/'.$route->uri();
            if (! isset($spec['paths'][$path])) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actual[] = strtolower($method).' '.$path;
                }
            }
        }
        $expected = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $op) {
                $expected[] = $method.' '.$path;
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        foreach (['Role', 'SubscriptionState', 'OrderState', 'PaymentState', 'TransactionKind', 'SubscriptionAction', 'ErrorCode', 'PlaybackReason'] as $name) {
            $class = 'App\\Enums\\'.$name;
            $this->assertSame($spec['components']['schemas'][$name]['enum'], array_column($class::cases(), 'value'));
        }
    }
}
