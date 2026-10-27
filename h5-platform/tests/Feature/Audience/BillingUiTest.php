<?php

namespace Tests\Feature\Audience;

use App\Data\ViewerIdentity;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Audience\Billing\BillingText;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Gateway\UnconfiguredGateway;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\SettlementService;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Billing\BillingTestCase;

/** Real Identity + Billing providers and PostgreSQL; only the external payment boundary is a testing fixture. */
final class BillingUiTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['h5.modules.audience.ready' => true]);
        $this->app->register(AudienceServiceProvider::class);
        Route::middleware('web')->group(base_path('routes/modules/audience.php'));
    }

    private function loginViewer(bool $verified = true): ViewerIdentity
    {
        $viewer = $this->viewer($verified);
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);

        return $viewer;
    }

    private function buyInput(): array
    {
        $offer = $this->get('/membership?purchase=1')->assertOk()->viewData('offer');

        return ['offer_id' => $offer->offer_id, 'price_version' => $offer->price_version, 'confirmed' => '1'];
    }

    private function resumeInput(): array
    {
        $quote = $this->get('/membership?resume=1')->assertOk()->viewData('quote');

        return ['quote_id' => $quote->quote_id, 'confirmation_version' => $quote->confirmation_version,
            'confirmed_currency' => $quote->currency, 'confirmed_amount_minor' => $quote->next_renewal_amount_minor,
            'confirmed_next_renewal_at' => $quote->next_renewal_at, 'confirmed' => '1'];
    }

    public function test_guest_uses_real_offer_and_empty_price_is_not_filled_with_sample_data(): void
    {
        $this->get('/membership')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('USD 3.00')->assertSee('Sign in to purchase')->assertDontSee('action="/membership/checkout"', false);
        DB::table('billing_prices')->delete();
        $this->get('/membership')->assertStatus(503)->assertSee('Payment is not available')->assertDontSee('USD 3.00');
    }

    public function test_email_verification_precedes_checkout_and_keeps_only_current_return_path(): void
    {
        $this->loginViewer(false);
        $path = '/dramas/'.BillingStore::id().'/episodes/'.BillingStore::id().'?position=27';
        $this->get('/membership?purchase=1&return_to='.rawurlencode($path))->assertOk()->assertSee('Verify email')
            ->assertDontSee('action="/membership/checkout"', false)->assertSessionHas('identity.return_to', '/membership?purchase=1')
            ->assertSessionHas('audience.billing.return_to', $path);
        $this->get('/membership?return_to='.rawurlencode('https://evil.example/watch/x'))->assertOk()->assertSessionHas('audience.billing.return_to', $path);
        $this->assertDatabaseCount('billing_orders', 0);
    }

    public function test_unconfigured_stripe_is_an_explicit_failure_without_order_or_membership(): void
    {
        $viewer = $this->loginViewer();
        $input = $this->buyInput();
        $this->app->instance(PaymentGateway::class, new UnconfiguredGateway);
        $this->post('/membership/checkout', $input)->assertStatus(503)->assertSee('Payment is not available');
        $this->assertDatabaseCount('billing_orders', 0);
        $this->assertFalse($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
    }

    public function test_checkout_uses_its_own_fixed_deadline_and_repeated_click_reuses_the_order(): void
    {
        $this->travelTo(now()->setTime(12, 0, 0));
        $viewer = $this->loginViewer();
        $input = $this->buyInput();
        $this->travel(20)->minutes();
        $response = $this->post('/membership/checkout', $input)->assertRedirect();
        $url = $response->headers->get('Location');
        $order = DB::table('billing_orders')->first();
        $this->get($url)->assertOk()->assertSee('12:51:00 UTC')->assertDontSee('12:30:00 UTC')->assertSee('data-payment-countdown', false);
        $this->post('/membership/checkout', $input)->assertRedirect($url);
        $this->travel(31)->minutes();
        $this->get($url)->assertOk()->assertSee('12:51:00 UTC')->assertDontSee('Open secure payment page');
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertSame(1, $this->gateway->createCalls);
        $this->assertFalse($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        $this->assertSame('pending', $order->state);
    }

    public function test_unknown_checkout_can_recover_only_the_confirmed_original_after_quote_expiry(): void
    {
        $this->travelTo(now()->setTime(12, 0, 0));
        $viewer = $this->loginViewer();
        $input = $this->buyInput();
        $this->travel(20)->minutes();
        $this->gateway->failCreate = true;
        $this->post('/membership/checkout', $input)->assertRedirect('/membership');
        $original = DB::table('billing_orders')->first();
        $attempt = session('audience.billing.attempts.'.$input['offer_id']);
        $this->assertSame('client:'.$attempt['key'], $original->idempotency_key);
        $this->assertSame('pending', $attempt['state']);
        $this->get('/membership?purchase=1')->assertOk()->assertSee('Check original payment')->assertDontSee('Confirm and open payment');
        $this->assertSame(1, $this->gateway->createCalls); // Rendering must not retry or create payment.
        $this->assertDatabaseCount('billing_offers', 1);

        // Background reconciliation later recovers the same immutable request, after Offer expiry.
        $this->travel(15)->minutes();
        $this->gateway->failCreate = false;
        $this->engine->retryPendingOperations();
        $recovered = DB::table('billing_orders')->first();
        $this->assertSame($original->id, $recovered->id);
        $this->assertSame($original->checkout_request, $recovered->checkout_request);
        $this->assertSame($original->expires_at, $recovered->expires_at);
        $this->assertSame($original->fingerprint, $recovered->fingerprint);
        $this->assertSame('pending', $recovered->state);
        $calls = $this->gateway->createCalls;
        $recheck = ['offer_id' => $input['offer_id'], 'price_version' => $input['price_version'], 'recheck' => '1'];
        $url = '/billing/return?order_id='.$original->id;
        $this->post('/membership/checkout', $recheck)->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('12:51:00 UTC')->assertSee('Open secure payment page');
        $this->get('/membership')->assertOk()->assertSee('View latest payment order')->assertSee($url, false)->assertDontSee('Check original payment');
        $this->post('/membership/checkout', $recheck)->assertRedirect($url);
        $this->assertSame($calls, $this->gateway->createCalls);
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertFalse($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
    }

    public function test_missing_or_other_account_recovery_credentials_cannot_guess_or_create_checkout(): void
    {
        $viewer = $this->loginViewer();
        $input = $this->buyInput();
        $recheck = ['offer_id' => $input['offer_id'], 'price_version' => $input['price_version'], 'recheck' => '1'];
        // Merely reading an Offer does not authorize the recovery endpoint to make a purchase.
        $this->post('/membership/checkout', $recheck)->assertRedirect('/membership');
        $this->assertDatabaseCount('billing_orders', 0);
        $this->gateway->failCreate = true;
        $this->post('/membership/checkout', $input)->assertRedirect('/membership');
        $calls = $this->gateway->createCalls;
        $this->loginViewer();
        $this->post('/membership/checkout', $recheck)->assertRedirect('/membership');
        $this->assertSame($calls, $this->gateway->createCalls);
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1, 'audience' => []]);
        $this->get('/membership')->assertOk()->assertSee('The original payment details are not available in this browser session')
            ->assertDontSee('Check original payment')->assertDontSee('View latest payment order');
        $this->post('/membership/checkout', $recheck)->assertRedirect('/membership');
        $this->assertSame($calls, $this->gateway->createCalls);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_temporarily_unavailable_recheck_keeps_original_recovery_credentials(): void
    {
        $this->loginViewer();
        $input = $this->buyInput();
        $this->gateway->failCreate = true;
        $this->post('/membership/checkout', $input)->assertRedirect('/membership');
        $attempt = session('audience.billing.attempts.'.$input['offer_id']);
        $this->gateway->unavailable = true;
        $this->post('/membership/checkout', ['offer_id' => $input['offer_id'], 'price_version' => $input['price_version'], 'recheck' => '1'])
            ->assertStatus(503)->assertSee('Payment is not available');
        $this->assertSame($attempt, session('audience.billing.attempts.'.$input['offer_id']));
        $this->get('/membership')->assertOk()->assertSee('Check original payment');
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_successful_checkout_remains_reachable_from_membership(): void
    {
        $this->loginViewer();
        $input = $this->buyInput();
        $this->post('/membership/checkout', $input)->assertRedirect();
        $order = DB::table('billing_orders')->first();
        $this->get('/membership')->assertOk()->assertSee('View latest payment order')
            ->assertSee('/billing/return?order_id='.$order->id, false)->assertDontSee('Check original payment');
        $this->assertSame(1, $this->gateway->createCalls);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_payment_return_login_keeps_only_a_controlled_order_path(): void
    {
        $id = BillingStore::id();
        $this->get('/billing/return?order_id='.$id)->assertRedirect('/identity/login?return_to='.rawurlencode('/billing/return?order_id='.$id));
        $this->get('/billing/return?order_id=https://evil.example')->assertRedirect('/identity/login?return_to='.rawurlencode('/membership'));
        $this->get('/billing/return?order_id[]=bad')->assertRedirect('/identity/login?return_to='.rawurlencode('/membership'));
    }

    public function test_browser_success_parameters_cannot_grant_access_and_other_orders_are_private(): void
    {
        $viewer = $this->loginViewer();
        $order = $this->checkoutFor($viewer);
        $this->get('/billing/return?order_id='.$order->id.'&success=true&membership=active')->assertOk()
            ->assertSee('No active paid access')->assertSee('Payment is not yet confirmed');
        $this->loginViewer();
        $this->get('/billing/return?order_id='.$order->id)->assertNotFound()->assertSee('This order or confirmation is unavailable')->assertDontSee('USD 3.00')->assertDontSee('Payment is not yet confirmed');
        $this->assertDatabaseCount('billing_entitlements', 0);
    }

    public function test_checkout_without_provider_expiry_never_displays_a_cached_link_or_fake_countdown(): void
    {
        $this->travelTo(now()->setTime(12, 0, 0));
        $this->loginViewer();
        $input = $this->buyInput();
        $response = $this->post('/membership/checkout', $input)->assertRedirect();
        $url = $response->headers->get('Location');
        $orderId = DB::table('billing_orders')->value('id');
        $savedKey = 'audience.billing.checkouts.'.$orderId;
        $saved = session($savedKey);
        $saved['checkout']['checkout_url'] = 'https://www.sandbox.paypal.com/checkoutnow?token=approved-test-token';
        $saved['checkout']['expires_at'] = null;
        session()->put($savedKey, $saved);

        $this->get($url)->assertOk()->assertSee('The payment link is no longer shown while the order is being checked.')
            ->assertDontSee('https://www.sandbox.paypal.com/checkoutnow?token=approved-test-token', false)
            ->assertDontSee('data-payment-countdown', false)->assertDontSee('data-billing-deadline', false)
            ->assertSee('Recheck this original payment')->assertSee('No active paid access');

        $saved['checkout']['checkout_url'] = null;
        session()->put($savedKey, $saved);
        $this->get($url)->assertOk()->assertSee('The payment provider has not supplied an expiry time.')
            ->assertDontSee('data-payment-countdown', false)->assertSee('Recheck this original payment');

        $createCalls = $this->gateway->createCalls;
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($url);
        $this->assertSame($createCalls, $this->gateway->createCalls);
        $this->get($url)->assertOk()->assertDontSee('https://www.sandbox.paypal.com/checkoutnow?token=approved-test-token', false)
            ->assertSee('Awaiting payment');
        $this->assertDatabaseCount('billing_orders', 1);
        $this->assertDatabaseCount('billing_entitlements', 0);
    }

    public function test_recheck_post_reuses_only_the_confirmed_attempt_without_creating_another_order(): void
    {
        $this->travelTo(now()->setTime(12, 0, 0));
        $this->loginViewer();
        $input = $this->buyInput();
        $response = $this->post('/membership/checkout', $input)->assertRedirect();
        $url = $response->headers->get('Location');
        $orderId = DB::table('billing_orders')->value('id');
        $savedKey = 'audience.billing.checkouts.'.$orderId;
        $saved = session($savedKey);
        $attempt = session('audience.billing.attempts.'.$input['offer_id']);
        session()->put('audience.billing.attempts.'.$input['offer_id'], $attempt);
        $beforeCalls = $this->gateway->createCalls;

        $this->travel(32)->minutes();
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($url);
        $this->assertSame($beforeCalls, $this->gateway->createCalls);
        $this->assertDatabaseCount('billing_orders', 1);

        // Without the original confirmed session credentials, POST can only fall back to status display.
        session()->forget('audience.billing.attempts.'.$input['offer_id']);
        $this->post('/billing/return/recheck', ['order_id' => $orderId])->assertRedirect($url)->assertSessionHas('audience.billing.notice', 'recovery_missing');
        $this->assertSame($beforeCalls, $this->gateway->createCalls);
        $this->assertDatabaseCount('billing_orders', 1);
    }

    public function test_return_rejects_untrusted_or_non_https_checkout_approval_links(): void
    {
        $this->loginViewer();
        $input = $this->buyInput();
        $url = $this->post('/membership/checkout', $input)->headers->get('Location');
        $orderId = DB::table('billing_orders')->value('id');
        $savedKey = 'audience.billing.checkouts.'.$orderId;
        $saved = session($savedKey);
        $saved['checkout']['checkout_url'] = 'https://www.sandbox.paypal.com.attacker.invalid/checkoutnow?token=x';
        $saved['checkout']['expires_at'] = null;
        session()->put($savedKey, $saved);

        $this->get($url)->assertOk()->assertDontSee('attacker.invalid')->assertDontSee('Open secure payment page')
            ->assertSee('Payment is not yet confirmed. Check the status before trying again.');
    }

    public function test_cancel_and_resume_read_original_quote_and_require_explicit_confirmation(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $this->get('/membership?purchase=1')->assertOk()->assertSee('Cancel automatic renewal')->assertDontSee('action="/membership/checkout"', false);
        $this->post('/membership/cancel', ['confirmed' => '1'])->assertRedirect('/membership');
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $page = $this->get('/membership?resume=1')->assertOk()->assertSee('USD 12.00')->assertDontSee('USD 15.00')->assertSee('No charge is made now');
        $quote = $page->viewData('quote');
        $input = ['quote_id' => $quote->quote_id, 'confirmation_version' => $quote->confirmation_version,
            'confirmed_currency' => $quote->currency, 'confirmed_amount_minor' => $quote->next_renewal_amount_minor,
            'confirmed_next_renewal_at' => $quote->next_renewal_at];
        $calls = $this->gateway->renewalCalls;
        $this->post('/membership/resume', $input)->assertSessionHasErrors('confirmed');
        $this->assertSame($calls, $this->gateway->renewalCalls);
        $this->post('/membership/resume', $input + ['confirmed' => '1'])->assertRedirect('/membership');
        $membership = $this->engine->membership($viewer->id, now()->toDateTimeImmutable());
        $this->assertTrue($membership->renewal_enabled);
        $this->assertSame(BillingStore::iso($sub->period_end), $membership->access_until);
        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_stale_or_tampered_resume_rereads_but_does_not_resume_automatically(): void
    {
        [$viewer] = $this->paidFixture();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $this->engine->cancel($viewer);
        $input = $this->resumeInput();
        $input['confirmed_amount_minor'] = 1;
        $this->post('/membership/resume', $input)->assertRedirect('/membership?resume=1')->assertSessionHas('audience.billing.notice', 'stale');
        $fresh = $this->resumeInput();
        $this->travel(5)->minutes();
        $this->post('/membership/resume', $fresh)->assertRedirect('/membership?resume=1');
        $this->get('/membership?resume=1')->assertOk()->assertSee('Confirm resuming renewal');
        $this->assertFalse($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->renewal_enabled);
        $this->assertSame(1, $this->gateway->renewalCalls);
    }

    public function test_expired_offer_requires_new_price_confirmation_without_creating_order(): void
    {
        $this->loginViewer();
        $input = $this->buyInput();
        $this->travel(30)->minutes();
        $this->post('/membership/checkout', $input)->assertRedirect('/membership?purchase=1')->assertSessionHas('audience.billing.notice', 'stale');
        $this->get('/membership?purchase=1')->assertOk()->assertSee('Confirm and open payment');
        $this->assertDatabaseCount('billing_orders', 0);
    }

    public function test_partial_refund_shows_original_access_and_unresolved_renewal_separately(): void
    {
        [$viewer, $order, $sub] = $this->paidFixture();
        $this->actingAs(Viewer::findOrFail($viewer->id), 'web')->withSession(['identity.version.web' => 1]);
        $this->gateway->refunds['ch_'.$order->id] = [['id' => 're_audience_partial', 'amount_minor' => 100, 'currency' => 'USD', 'status' => 'succeeded']];
        $this->gateway->failRenewal = true;
        app(SettlementService::class)->syncRefunds('ch_'.$order->id);
        $this->get('/billing/return?order_id='.$order->id)->assertOk()->assertSee('A partial refund is recorded')
            ->assertSee('Membership is active')->assertSee('A request is being checked')->assertSee(BillingText::date(BillingStore::iso($sub->period_end)));
    }

    public function test_language_choice_keeps_usd_and_does_not_change_offer_terms(): void
    {
        $this->get('/membership?lang=zh_CN')->assertOk()->assertSee('会员中心')->assertSee('USD 3.00')->assertSee('USD 12.00');
        $this->get('/membership?lang=en')->assertOk()->assertSee('Membership')->assertSee('USD 3.00')->assertSee('USD 12.00');
    }
}
