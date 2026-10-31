<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\PromotableCatalog;
use App\Data\AttributionSnapshot;
use App\Data\PaymentSettled;
use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;
use App\Enums\Role;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Identity\Models\Staff;
use App\Support\ApiProblem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class AcquisitionTestCase extends TestCase
{
    use RefreshDatabase;

    protected array $dramas = [];

    protected array $privateFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(AcquisitionServiceProvider::class);
        config(['h5.modules.acquisition.ready' => true, 'acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false,
            'acquisition.platform_reviewed' => false, 'acquisition.credentials_file' => '', 'acquisition.meta_api_version' => 'v26.0']);
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $session = app('session.store');
        $this->app->rebinding('request', static function ($app, $request) use ($session): void {
            $request->setLaravelSession($session);
        });
        $id = AcquisitionStore::id();
        $this->dramas[$id] = new PromotableDrama($id, '明确的 testing 片单样例', 'zh_CN', 'US', 6);
        // Explicit testing double of the 1.0.3 staff catalog; ordinary local requests use the real binding.
        $test = $this;
        $this->app->instance(PromotableCatalog::class, new class($test) implements PromotableCatalog
        {
            public function __construct(private AcquisitionTestCase $test) {}

            public function promotable(?string $cursor = null, int $limit = 20, string $locale = 'en'): PromotableDramaPage
            {
                return new PromotableDramaPage(array_values($this->test->fixtureDramas()), null);
            }

            public function promotableDrama(string $dramaId, string $locale = 'en'): PromotableDrama
            {
                return $this->test->fixtureDramas()[$dramaId] ?? throw new ApiProblem('NOT_FOUND', 404, '剧目不存在');
            }
        });
    }

    public function fixtureDramas(): array
    {
        return $this->dramas;
    }

    protected function tearDown(): void
    {
        foreach ($this->privateFiles as $path) {
            @unlink($path);
            if (preg_match('~/h5-acq-secret-[a-f0-9]{16}$~', dirname($path))) {
                @rmdir(dirname($path));
            }
        }
        parent::tearDown();
    }

    protected function staff(Role $role = Role::Acquisition): Staff
    {
        $s = Staff::create(['name' => 'Acquisition test '.$role->value, 'email' => AcquisitionStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $s->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $s->refresh();
    }

    protected function signIn(?Staff $staff = null): Staff
    {
        $staff ??= $this->staff();
        $this->actingAs($staff, 'staff')->withSession(['identity.version.staff' => $staff->session_version, 'identity.mfa_staff' => $staff->id]);
        $session = app('session.store');
        $cookie = config('h5.workspace').'_admin';
        config(['session.cookie' => $cookie, 'session.encrypt' => true]);
        $session->setName($cookie);
        auth('staff')->login($staff);
        $session->put('identity.version.staff', $staff->session_version);
        $session->put('identity.mfa_staff', $staff->id);
        $session->save();
        request()->setLaravelSession($session);
        $this->withCookie($cookie, $session->getId());

        return $staff;
    }

    protected function pixelInput(string $platform = 'meta', string $environment = 'test'): array
    {
        return ['name' => 'Local test '.$platform, 'platform' => $platform, 'external_id' => $platform === 'meta' ? (string) random_int(1000000000, 9999999999) : 'TEST'.bin2hex(random_bytes(10)),
            'environment' => $environment, 'active' => true, 'version' => 0];
    }

    protected function assets(string $platform = 'meta', string $environment = 'test'): array
    {
        $manager = app(AssetManager::class);
        $pixel = $manager->savePixel(null, $this->pixelInput($platform, $environment));
        $link = $manager->saveLink(null, ['name' => 'Local test link', 'platform' => $platform, 'drama_id' => array_key_first($this->dramas),
            'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);

        return [$pixel, $link];
    }

    protected function source(array $pixel, array $link, bool $simulation = true): AttributionSnapshot
    {
        $id = AcquisitionStore::id();
        $captured = now()->subMinute()->startOfSecond();
        DB::table('acquisition_sources')->insert([
            'id' => $id, 'link_id' => $link['id'], 'pixel_id' => $pixel['id'], 'pixel_version' => $pixel['version'], 'channel' => $pixel['platform'],
            'consent' => 'granted', 'policy_version' => 'TEST_FIXTURE_ONLY_NOT_D05', 'captured_at' => $captured, 'expires_at' => now()->addDay(),
            'context_encrypted' => Crypt::encryptString(json_encode(['page_url' => 'https://localhost/dramas/'.$link['drama_id'],
                'ip' => '8.8.8.8', 'user_agent' => 'Explicit PHPUnit simulated browser', 'email' => 'must-not-send@example.test'], JSON_THROW_ON_ERROR)),
            'is_simulation' => $simulation,
        ]);

        return new AttributionSnapshot($id, $link['id'], $pixel['platform'], $pixel['version'], $captured->toIso8601String(), 'granted');
    }

    protected function event(?AttributionSnapshot $source = null, TransactionKind $kind = TransactionKind::FirstSubscription, bool $test = true): PaymentSettled
    {
        return new PaymentSettled(AcquisitionStore::id(), 'PaymentSettled.v1', AcquisitionStore::id(), AcquisitionStore::id(), AcquisitionStore::id(),
            $kind, $kind === TransactionKind::FirstSubscription, $kind === TransactionKind::FirstSubscription ? 300 : 1200, 'USD', now()->toIso8601String(), $test, $source);
    }

    protected function queueEvent(string $platform = 'meta', string $environment = 'test', bool $simulation = true): array
    {
        [$pixel, $link] = $this->assets($platform, $environment);
        $source = $this->source($pixel, $link, $simulation);
        $event = $this->event($source);
        app(PaymentReceiver::class)->handle($event);

        return [$pixel, $link, $source, $event, DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->first()];
    }

    protected function httpCredentials(object $delivery): void
    {
        config(['acquisition.transport' => 'http', 'acquisition.external_enabled' => true, 'acquisition.platform_reviewed' => true,
            'app.url' => 'https://localhost']);
        $directory = realpath(sys_get_temp_dir()).'/h5-acq-secret-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/credentials.json';
        file_put_contents($path, json_encode([$delivery->pixel_id.':'.$delivery->pixel_version => ['platform' => $delivery->platform,
            'external_id' => $delivery->external_id, 'environment' => $delivery->environment, 'token' => 'UNIT-TEST-NOT-A-REAL-SECRET', 'test_event_code' => 'UNIT_TEST']], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        config(['acquisition.credentials_file' => $path]);
        $this->privateFiles[] = $path;
    }

    protected function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (ApiProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
