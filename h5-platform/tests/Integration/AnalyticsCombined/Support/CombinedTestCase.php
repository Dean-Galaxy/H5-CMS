<?php

namespace Tests\Integration\AnalyticsCombined\Support;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\Verification;
use App\Support\DisabledBehaviorEventRecorder;
use App\Support\UnavailableBillingAnalyticsReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\Feature\Content\ContentTestCase;

/** Pending the complete 00 candidate. Never skip absent consumers or replace them with successful spies. */
abstract class CombinedTestCase extends ContentTestCase
{
    protected FakeGateway $gateway;
    protected object $drama;
    protected array $link;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertNotInstanceOf(DisabledBehaviorEventRecorder::class, app(BehaviorEventRecorder::class));
        $this->assertNotInstanceOf(UnavailableBillingAnalyticsReader::class, app(BillingAnalyticsReader::class));
        $this->assertTrue(class_exists(AnalyticsReport::class), '05 report consumer must be received before this suite runs');
        config(['acquisition.analytics_enabled' => true, 'acquisition.transport' => 'disabled',
            'acquisition.external_enabled' => false, 'billing.quote_policy_approved' => true, 'billing.new_purchase_provider' => 'stripe']);
        if (! DB::table('billing_prices')->exists()) {
            DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 1, 'regular_amount_minor' => 1200,
                'first_week_amount_minor' => 300, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        }
        $this->gateway = new FakeGateway;
        app()->instance(PaymentGateway::class, $this->gateway);
        $this->drama = $this->fixture(6, 6, true);
        $manager = app(AssetManager::class);
        $pixel = $manager->savePixel(null, ['name' => '06 isolated analytics TEST', 'platform' => 'meta',
            'external_id' => '1060000001', 'environment' => 'test', 'active' => true, 'version' => 0]);
        $this->link = $manager->saveLink(null, ['name' => '06 isolated analytics source', 'platform' => 'meta',
            'drama_id' => $this->drama->id, 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);
        $this->anonymous();
        $this->withHeader('User-Agent', 'Mozilla/5.0 06 explicit local browser fixture');
    }

    protected function anonymous(): void
    {
        $name = config('h5.workspace').'_viewer';
        config(['session.cookie' => $name, 'session.encrypt' => true]);
        app('session')->forgetDrivers(); $this->app->forgetInstance('session.store'); auth()->forgetGuards();
        $store = app('session.store'); $store->start(); $store->save();
        unset($this->unencryptedCookies[$name]); $this->withCookie($name, $store->getId());
    }

    protected function consent(bool $analytics = true, bool $advertising = true): void
    {
        $this->postJson('/api/v1/tracking/consent', ['analytics' => $analytics, 'advertising' => $advertising,
            'policy_version' => 'analytics-v1'])->assertOk();
    }

    protected function landing(bool $analytics = true): void
    {
        $this->get('/r/'.basename($this->link['url']))->assertRedirect();
        $properties = ['page_instance_id' => (string) Str::uuid(), 'path' => '/dramas/'.$this->drama->id, 'drama_id' => $this->drama->id];
        if ($analytics) {
            $this->browser('landing_view', $properties);
        } else {
            $this->postJson('/api/v1/tracking/landing', $properties)->assertOk()->assertJsonPath('confirmed', true);
        }
    }

    protected function browser(string $eventName, array $properties = [], ?string $eventId = null): array
    {
        $event = ['event_id' => $eventId ?? (string) Str::uuid(), 'event_name' => $eventName,
            'properties' => $properties + ['page_instance_id' => (string) Str::uuid(), 'path' => '/']];
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
        return $event;
    }

    protected function registerAndVerify(): Viewer
    {
        // PHP integration checks the real signed URL handler; actual preview-mail browser click is a separate required run.
        Notification::fake();
        $email = 'analytics-'.Str::uuid().'@example.test';
        $this->postJson('/api/v1/auth/register', ['name' => '06 synthetic viewer', 'email' => $email,
            'password' => 'Synthetic06Password!', 'password_confirmation' => 'Synthetic06Password!'])->assertOk();
        $viewer = Viewer::where('email', $email)->sole();
        $this->assertNull($viewer->email_verified_at);
        $this->getJson(app(Verification::class)->url($viewer->fresh()))->assertOk();
        $this->assertTrue($viewer->fresh()->hasVerifiedEmail());
        return $viewer->fresh();
    }

    protected function checkout(): object
    {
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version,
            'confirmed' => '1'])->assertRedirect();
        return DB::table('billing_orders')->sole();
    }

    protected function names(): array
    {
        return DB::table('acquisition_behaviors')->orderBy('received_at')->pluck('event_name')->all();
    }

    protected function finance(): array
    {
        $this->asStaff($this->owner);
        return app(AnalyticsReport::class)->read(['test' => 'test'])['finance'];
    }
}
