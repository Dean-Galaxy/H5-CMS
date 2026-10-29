<?php

namespace Tests\Feature\Audience;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Billing\BillingTestCase;

final class AnalyticsBillingTest extends BillingTestCase
{
    private object $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        $this->recorder = new class implements BehaviorEventRecorder
        {
            public array $calls = [];

            public bool $fail = false;

            public function record(string $eventName, array $properties = [], ?string $deduplicationKey = null): bool
            {
                if ($this->fail) {
                    throw new \RuntimeException('synthetic unavailable');
                }
                $this->calls[] = [$eventName, $properties, $deduplicationKey];

                return true;
            }

            public function recordFact(string $eventName, array $properties, string $deduplicationKey): BehaviorRecordingResult
            {
                return BehaviorRecordingResult::Retry;
            }

            public function clearBrowserContext(): void {}
        };
        $this->app->instance(BehaviorEventRecorder::class, $this->recorder);
    }

    private function purchase(): array
    {
        $viewer = $this->viewer();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');

        return ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'];
    }

    public function test_original_checkout_and_order_states_use_trusted_ids_stable_keys_and_never_confirm_payment(): void
    {
        $input = $this->purchase();
        $url = $this->post('/membership/checkout', $input)->assertRedirect()->headers->get('Location');
        $this->post('/membership/checkout', $input)->assertRedirect($url);
        $created = array_values(array_filter($this->recorder->calls, fn ($call) => $call[0] === 'checkout_created'));
        $this->assertCount(1, $created);
        $pending = array_values(array_filter($this->recorder->calls, fn ($call) => $call[0] === 'payment_pending'));
        $this->assertSame($pending[0], $pending[1]);
        $order = DB::table('billing_orders')->first();
        $this->assertSame($order->id, $created[0][1]['order_id']);
        $this->assertSame($order->viewer_id, $created[0][1]['viewer_id']);
        $this->get($url.'&success=true')->assertOk();
        $this->assertContains('payment_pending', array_column($this->recorder->calls, 0));
        DB::table('billing_orders')->where('id', $order->id)->update(['state' => 'canceled']);
        $this->get($url)->assertOk();
        DB::table('billing_orders')->where('id', $order->id)->update(['state' => 'failed', 'payment_state' => 'failed']);
        $this->get($url)->assertOk();
        $this->assertContains('payment_cancelled', array_column($this->recorder->calls, 0));
        $this->assertContains('payment_failed', array_column($this->recorder->calls, 0));
        $this->assertNotContains('payment_confirmed', array_column($this->recorder->calls, 0));
        $this->assertSame(1, $this->gateway->createCalls);
    }

    public function test_unknown_checkout_is_not_recorded_as_failure_and_analytics_failure_cannot_break_checkout(): void
    {
        $input = $this->purchase();
        $this->gateway->failCreate = true;
        $this->post('/membership/checkout', $input)->assertRedirect('/membership');
        $this->assertNotContains('checkout_failed', array_column($this->recorder->calls, 0));
        $this->gateway->failCreate = false;
        $this->engine->retryPendingOperations();
        $this->recorder->fail = true;
        $this->post('/membership/checkout', $input)->assertRedirect();
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_entitlements', 0);
    }

    public function test_unconfigured_checkout_reports_a_safe_checkout_failure_without_claiming_a_payment_failure(): void
    {
        $input = $this->purchase();
        $this->app->instance(PaymentGateway::class, new UnconfiguredGateway);
        $this->post('/membership/checkout', $input)->assertStatus(503);
        $this->assertSame('checkout_failed', $this->recorder->calls[0][0]);
        $this->assertSame('unavailable', $this->recorder->calls[0][1]['error_code']);
        $this->assertNotContains('payment_failed', array_column($this->recorder->calls, 0));
        $this->assertDatabaseCount('billing_orders', 0);
    }
}
