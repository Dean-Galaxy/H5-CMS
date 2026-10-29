<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Billing\Services\BillingBehaviorOutbox;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\FinancialAnalyticsReader;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingTestCase;

final class AnalyticsProvidersTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond()->addMilliseconds(100));
        $this->app->register(AcquisitionServiceProvider::class);
        $this->app->register(AudienceServiceProvider::class);
        config(['h5.modules.acquisition.ready' => true, 'h5.modules.audience.ready' => true, 'acquisition.analytics_enabled' => true,
            'acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function settle(object $order): void
    {
        $sub = DB::table('billing_subscriptions')->find($order->subscription_id);
        $sid = 'sub_'.$sub->id;
        $iid = 'in_'.$order->id;
        $start = now()->startOfSecond();
        $end = $start->addWeek();
        $this->gateway->subscriptions[$sid] = ['id' => $sid, 'customer_id' => $sub->customer_id, 'status' => 'active', 'cancel_at_period_end' => false,
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'regular_amount_minor' => 1200, 'currency' => 'USD', 'order_id' => $order->id, 'latest_invoice_id' => $iid, 'is_test' => true];
        $this->gateway->checkouts[$order->checkout_id] = ['id' => $order->checkout_id, 'status' => 'complete', 'payment_status' => 'paid', 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id, 'is_test' => true];
        $this->gateway->invoices[$iid] = ['id' => $iid, 'subscription_id' => $sid, 'customer_id' => $sub->customer_id, 'order_id' => $order->id,
            'amount_minor' => 300, 'currency' => 'USD', 'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end), 'paid_at' => BillingStore::iso($start),
            'status' => 'paid', 'paid' => true, 'verified_payment' => true, 'charge_id' => 'ch_'.$order->id, 'is_test' => true, 'billing_reason' => 'subscription_create', 'regular_amount_minor' => 1200, 'discount_amount_minor' => 900, 'payment_state' => 'succeeded'];
        app(SettlementService::class)->syncCheckout($order->id);
    }

    public function test_real_identity_audience_checkout_reader_and_delayed_payment_outbox_survive_logout_without_consent_withdrawal(): void
    {
        $this->assertInstanceOf(BehaviorRecorder::class, app(BehaviorEventRecorder::class));
        $this->assertInstanceOf(FinancialAnalyticsReader::class, app(BillingAnalyticsReader::class));
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/tracking/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view', 'properties' => ['page_instance_id' => (string) Str::uuid(), 'path' => '/']]]])->assertJsonPath('accepted', 1);
        $subject = DB::table('acquisition_subjects')->sole()->id;
        $this->postJson('/api/v1/auth/register', ['name' => 'Combined local fixture', 'email' => 'combined@example.test', 'password' => 'LocalPassword1234', 'password_confirmation' => 'LocalPassword1234'])->assertOk();
        $viewer = Viewer::sole();
        $viewer->markEmailAsVerified();
        Event::dispatch(new Verified($viewer));
        $this->assertDatabaseHas('acquisition_subjects', ['id' => $subject, 'viewer_id' => $viewer->id]);
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'])->assertRedirect();
        $order = DB::table('billing_orders')->sole();
        $this->assertDatabaseHas('acquisition_analytics_orders', ['order_id' => $order->id, 'subject_id' => $subject]);
        $this->settle($order);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(session('tracking.source'));
        $this->assertDatabaseHas('acquisition_privacy', ['analytics' => 'granted']);
        // No authenticated request/session when the real reliable producer dispatches.
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(1, app(OutboxService::class)->dispatchPending());
        $this->assertSame(0, app(OutboxService::class)->dispatchPending());
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
        $this->assertDatabaseHas('acquisition_behaviors', ['event_name' => 'payment_confirmed', 'subject_id' => $subject]);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $payment = DB::table('billing_payments')->sole();
        $this->gateway->refunds[$payment->provider_charge_id] = [['id' => 're_combined', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']];
        app(SettlementService::class)->syncRefunds($payment->provider_charge_id);
        app(BillingBehaviorOutbox::class)->dispatchPending();
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'refund_confirmed')->count());
        $this->assertSame(100, app(BillingAnalyticsReader::class)->payments([$payment->id])[$payment->id]['refunded_amount_minor']);
        Http::assertNothingSent();
    }

    public function test_true_withdrawal_after_logout_by_same_viewer_stops_delayed_fact_without_touching_payment(): void
    {
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/auth/register', ['name' => 'Withdraw fixture', 'email' => 'withdraw@example.test', 'password' => 'LocalPassword1234', 'password_confirmation' => 'LocalPassword1234'])->assertOk();
        $viewer = Viewer::sole();
        $viewer->markEmailAsVerified();
        Event::dispatch(new Verified($viewer));
        $offer = $this->get('/membership?purchase=1')->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'])->assertRedirect();
        $order = DB::table('billing_orders')->sole();
        $this->settle($order);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        // A later authenticated choice applies to that viewer's previous evidence even without active tracking context.
        $this->postJson('/api/v1/auth/login', ['email' => $viewer->email, 'password' => 'LocalPassword1234'])->assertOk();
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->assertDatabaseHas('acquisition_privacy', ['viewer_id' => $viewer->id, 'analytics' => 'denied', 'advertising' => 'denied']);
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(1, app(OutboxService::class)->dispatchPending());
        $this->assertSame(0, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        Http::assertNothingSent();
    }

    public function test_account_b_does_not_inherit_a_and_cannot_withdraw_as_consent_for_a(): void
    {
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/auth/register', ['name' => 'Account A', 'email' => 'account-a@example.test', 'password' => 'LocalPassword1234', 'password_confirmation' => 'LocalPassword1234'])->assertOk();
        $a = Viewer::sole();
        $a->markEmailAsVerified();
        Event::dispatch(new Verified($a));
        $offer = $this->get('/membership?purchase=1')->viewData('offer');
        $this->post('/membership/checkout', ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'])->assertRedirect();
        $order = DB::table('billing_orders')->sole();
        $this->settle($order);
        $privacy = DB::table('acquisition_privacy')->where('viewer_id', $a->id)->sole();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/register', ['name' => 'Account B', 'email' => 'account-b@example.test', 'password' => 'LocalPassword1234', 'password_confirmation' => 'LocalPassword1234'])->assertOk();
        $b = Viewer::where('email', 'account-b@example.test')->sole();
        $this->getJson('/api/v1/tracking/context')->assertJsonPath('consent.analytics', 'unknown')->assertJsonPath('pending_landing', false);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->assertDatabaseHas('acquisition_privacy', ['id' => $privacy->id, 'viewer_id' => $a->id, 'analytics' => 'granted', 'advertising' => 'granted']);
        $this->assertSame(0, DB::table('acquisition_subjects')->where('viewer_id', $b->id)->count());
        $this->app->instance('request', Request::create('/'));
        $this->assertSame(1, app(OutboxService::class)->dispatchPending());
        $this->assertDatabaseHas('acquisition_behaviors', ['event_name' => 'payment_confirmed', 'viewer_id' => $a->id, 'privacy_id' => $privacy->id]);
        $this->assertDatabaseCount('billing_payments',1);
        Http::assertNothingSent();
    }
}
