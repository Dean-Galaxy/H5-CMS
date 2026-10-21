<?php

namespace Tests\Feature\Billing;

use App\Contracts\MembershipReader;
use App\Data\Membership;
use App\Enums\Role;
use App\Enums\SubscriptionState;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Filament\Pages\BillingDesk;
use App\Modules\Billing\Gateway\PaymentGateway;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Staff;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Billing\Support\FakeGateway;
use Tests\TestCase;

final class BillingDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(BillingServiceProvider::class);
        config(['billing.driver' => 'unconfigured']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Livewire::test replaces the Request and disables web middleware. Preserve the
        // same real session on each synthetic request; production authentication stays intact.
        $session = app('session.store');
        $this->app->rebinding('request', static function ($app, $request) use ($session): void {
            $request->setLaravelSession($session);
        });
    }

    public function test_owner_uses_dollar_inputs_and_exact_saved_price_survives_refresh(): void
    {
        $this->signIn(Role::Owner);
        $page = Livewire::test(BillingDesk::class)->assertOk()
            ->assertSet('tab', 'orders')->assertSet('price.version', 0)
            ->assertSet('price.regular_amount_usd', '')->assertSet('price.first_week_amount_usd', '')
            ->assertSee('Stripe 未配置')->assertDontSee('常规周价（USD）');
        $this->assertDatabaseCount('billing_prices', 0);
        $page->call('selectTab', 'prices')->assertReturned(true)
            ->set('price.regular_amount_usd', '12.34')->set('price.first_week_amount_usd', '3.21')
            ->call('savePrice')->assertReturned(true)->assertHasNoErrors()
            ->assertSet('price.version', 1)->assertSet('price.regular_amount_usd', '12.34');
        Notification::assertNotified('测试价格新版本已保存，原订阅约定价保留');
        $this->assertDatabaseHas('billing_prices', ['version' => 1, 'regular_amount_minor' => 1234, 'first_week_amount_minor' => 321, 'is_test' => true]);
        Livewire::withQueryParams(['tab' => 'prices'])->test(BillingDesk::class)->assertOk()
            ->assertSet('tab', 'prices')->assertSet('price.version', 1)
            ->assertSet('price.regular_amount_usd', '12.34')->assertSet('price.first_week_amount_usd', '3.21');
        $this->assertDatabaseCount('billing_prices', 1);
    }

    public function test_support_can_open_exact_order_without_price_controls_or_payment_credentials(): void
    {
        $support = $this->signIn(Role::Support);
        $orderId = $this->order();
        $page = Livewire::test(BillingDesk::class)->assertOk()
            ->assertDontSee('会员价格')->assertDontSee('常规周价')
            ->set('orderId', $orderId)->call('openCase')->assertReturned(true)->assertHasNoErrors()
            ->assertSee($orderId)->assertSee('$3.00 USD')->assertSee('$12.00 USD')
            ->assertDontSee('https://example.test/private-checkout');
        $caseId = $page->get('caseId');
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'order_id' => $orderId, 'staff_id' => $support->id]);
        $page->call('selectTab', 'support')->assertReturned(true)
            ->set('note', 'Testing only: request still needs verification.')
            ->call('addNote')->assertReturned(true)->assertHasNoErrors()
            ->assertSet('note', '')->assertSee('Testing only: request still needs verification.');
        $this->assertDatabaseCount('billing_notes', 1);
        $page->call('savePrice')->assertReturned(false)->assertHasErrors('billing');
        Notification::assertNotified('无权执行此操作');
        $page->call('selectTab', 'prices')->assertReturned(false)->assertSet('tab', 'support');
        $this->assertDatabaseCount('billing_prices', 0);
    }

    public function test_invalid_dollars_keep_the_form_and_save_pending_refuses_navigation(): void
    {
        $this->signIn(Role::Owner);
        $page = Livewire::test(BillingDesk::class)->call('selectTab', 'prices')
            ->set('price.regular_amount_usd', '12.345')->set('price.first_week_amount_usd', '3.00')
            ->call('savePending')->assertReturned(false)
            ->assertHasErrors('price.regular_amount_usd')->assertSet('tab', 'prices')
            ->assertSet('price.regular_amount_usd', '12.345')->assertSet('price.version', 0);
        $this->assertDatabaseCount('billing_prices', 0);
        $page->call('discardPending')->assertReturned(true)->assertHasNoErrors()
            ->assertSet('price.regular_amount_usd', '')->assertSet('price.first_week_amount_usd', '');
        $this->assertDatabaseCount('billing_prices', 0);
    }

    public function test_stale_price_editor_keeps_entered_dollars_until_explicit_discard(): void
    {
        $this->signIn(Role::Owner);
        $first = Livewire::test(BillingDesk::class)->call('selectTab', 'prices');
        $stale = Livewire::test(BillingDesk::class)->call('selectTab', 'prices');
        $first->set('price.regular_amount_usd', '15.00')->set('price.first_week_amount_usd', '4.50')
            ->call('savePrice')->assertReturned(true);
        $stale->set('price.regular_amount_usd', '18.00')->set('price.first_week_amount_usd', '5.00')
            ->call('savePrice')->assertReturned(false)->assertHasErrors('billing')
            ->assertSet('price.regular_amount_usd', '18.00')->assertSet('price.version', 0);
        $this->assertDatabaseCount('billing_prices', 1);
        $stale->call('discardPending')->assertReturned(true)->assertHasNoErrors()
            ->assertSet('price.regular_amount_usd', '15.00')->assertSet('price.version', 1);
    }

    public function test_refresh_context_and_forged_tabs_or_cases_do_not_bypass_scope(): void
    {
        $this->signIn(Role::Support);
        $own = app(BillingAdministration::class)->openCase($this->order());
        $page = Livewire::withQueryParams(['tab' => 'support', 'case' => $own])->test(BillingDesk::class)
            ->assertSet('tab', 'support')->assertSet('caseId', $own);
        $page->set('tab', 'prices')->assertDontSee('常规周价')->assertDontSee('会员价格');
        $this->signIn(Role::Support);
        Livewire::withQueryParams(['tab' => 'prices', 'case' => $own])->test(BillingDesk::class)
            ->assertSet('tab', 'orders')->assertSet('caseId', null)->assertDontSee('常规周价');
        Livewire::withQueryParams(['tab' => 'invalid', 'case' => 'not-a-uuid'])->test(BillingDesk::class)
            ->assertSet('tab', 'orders')->assertSet('caseId', null);
        $this->assertDatabaseCount('billing_notes', 0);
    }

    public function test_unsaved_support_note_requires_real_validation_and_discard_does_not_write(): void
    {
        $this->signIn(Role::Support);
        $page = Livewire::test(BillingDesk::class)->set('orderId', $this->order())->call('openCase')
            ->call('selectTab', 'support')->set('note', str_repeat('x', 2001))
            ->call('savePending')->assertReturned(false)->assertHasErrors('body')
            ->assertSet('note', str_repeat('x', 2001));
        $this->assertDatabaseCount('billing_notes', 0);
        $page->call('discardPending')->assertReturned(true)->assertSet('note', '');
        $this->assertDatabaseCount('billing_notes', 0);
        $page->set('identityVerified', true)->set('cancelRequested', true)
            ->set('note', 'Verified the current cancellation request.')
            ->call('savePending')->assertReturned(true)->assertHasNoErrors()->assertSet('note', '');
        $this->assertDatabaseCount('billing_notes', 1);
        $this->assertDatabaseHas('billing_cases', ['id' => $page->get('caseId'), 'identity_verified' => true, 'cancel_requested' => true]);
    }

    public function test_payment_refund_and_next_renewal_amounts_render_as_precise_dollars(): void
    {
        $this->signIn(Role::Owner);
        $orderId = $this->order();
        $order = DB::table('billing_orders')->find($orderId);
        // Read-only presentation fixture in the isolated test DB; no claim of settlement.
        DB::table('billing_payments')->insert([
            'id' => BillingStore::id(), 'order_id' => $orderId, 'viewer_id' => $order->viewer_id,
            'subscription_id' => $order->subscription_id, 'provider_invoice_id' => 'in_ui_'.BillingStore::id(),
            'amount_minor' => 300, 'refunded_amount_minor' => 1, 'currency' => 'USD', 'is_test' => true,
            'period_start' => now(), 'period_end' => now()->addWeek(), 'paid_at' => now(), 'created_at' => now(),
        ]);
        $this->mock(MembershipReader::class, function ($mock) {
            $mock->shouldReceive('membership')->andReturn(new Membership(true, now()->toIso8601ZuluString(),
                now()->addWeek()->toIso8601ZuluString(), true, SubscriptionState::Active, false,
                now()->addWeek()->toIso8601ZuluString(), 1201, []));
        });
        $page = Livewire::test(BillingDesk::class)->set('orderId', $orderId)->call('openCase')
            ->assertReturned(true)->assertSee('$3.00 USD')->assertSee('$0.01 USD')->assertSee('$12.01 USD');
        DB::table('billing_payments')->where('order_id', $orderId)->update(['refunded_amount_minor' => 0]);
        $page->call('$refresh')->assertSee('$0.00 USD');
        $this->assertDatabaseHas('billing_payments', ['order_id' => $orderId, 'amount_minor' => 300, 'refunded_amount_minor' => 0]);
    }

    public function test_unauthed_page_does_not_bootstrap_owner_or_prices(): void
    {
        $this->assertDatabaseCount('identity_staff', 0);
        $this->get('/admin/billing-desk')->assertRedirect('/admin/login');
        $this->assertDatabaseCount('identity_staff', 0);
        $this->assertDatabaseCount('billing_prices', 0);
    }

    public function test_staff_without_mfa_proof_cannot_render_billing_data(): void
    {
        $this->signIn(Role::Owner);
        request()->session()->forget('identity.mfa_staff');
        request()->session()->save();
        $this->get('/admin/billing-desk')->assertRedirect('/admin/login');
        $this->assertDatabaseCount('billing_prices', 0);
    }

    public function test_other_roles_cannot_render_the_billing_page(): void
    {
        foreach ([Role::ContentOperator, Role::Acquisition] as $role) {
            $this->signIn($role);
            $this->get('/admin/billing-desk')->assertForbidden();
        }
        $this->assertDatabaseCount('billing_cases', 0);
        $this->assertDatabaseCount('billing_prices', 0);
    }

    public function test_unknown_support_cancellation_clears_consumed_verification_but_keeps_unsaved_note(): void
    {
        $this->signIn(Role::Support);
        $orderId = $this->order();
        $order = DB::table('billing_orders')->find($orderId);
        $providerId = 'sub_ui_'.$order->subscription_id;
        $customerId = 'cus_ui_'.$order->viewer_id;
        $start = now()->startOfSecond();
        $end = $start->copy()->addWeek();
        // Isolated local fixture; only the provider response is simulated. The case,
        // reservation, consumed verification and pending operation use real services.
        DB::table('billing_subscriptions')->insert([
            'id' => $order->subscription_id, 'viewer_id' => $order->viewer_id, 'first_order_id' => $orderId,
            'provider_id' => $providerId, 'customer_id' => $customerId, 'state' => 'active', 'provider_status' => 'active',
            'price_version' => 1, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300,
            'currency' => 'USD', 'is_test' => true, 'renewal_enabled' => true, 'resolution_required' => false,
            'period_start' => $start, 'period_end' => $end, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = new FakeGateway;
        $gateway->subscriptions[$providerId] = [
            'id' => $providerId, 'customer_id' => $customerId, 'order_id' => $orderId,
            'status' => 'active', 'cancel_at_period_end' => false,
            'period_start' => BillingStore::iso($start), 'period_end' => BillingStore::iso($end),
            'regular_amount_minor' => 1200, 'currency' => 'USD', 'is_test' => true,
        ];
        $gateway->failRenewal = true;
        $this->app->instance(PaymentGateway::class, $gateway);
        $page = Livewire::test(BillingDesk::class)->set('orderId', $orderId)->call('openCase')
            ->assertReturned(true)->call('selectTab', 'support')
            ->set('identityVerified', true)->set('cancelRequested', true)
            ->call('saveVerification')->assertReturned(true)->assertHasNoErrors();
        $caseId = $page->get('caseId');
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);
        $note = 'Keep this unsaved note while the accepted cancellation is reconciled.';
        $page->set('note', $note)->call('cancelForCustomer')->assertReturned(false)
            ->assertHasErrors('billing')->assertSee('付款或订阅正在核对，请稍后刷新')
            ->assertSet('identityVerified', false)->assertSet('cancelRequested', false)
            ->assertSet('savedVerification', ['identity_verified' => false, 'cancel_requested' => false])
            ->assertSet('note', $note)->assertSet('caseId', $caseId)->assertSet('tab', 'support');
        $this->assertSame(1, $gateway->renewalCalls);
        $this->assertTrue($gateway->subscriptions[$providerId]['cancel_at_period_end']);
        $this->assertDatabaseHas('billing_cases', [
            'id' => $caseId, 'identity_verified' => false, 'cancel_requested' => false, 'verified_at' => null,
        ]);
        $this->assertDatabaseHas('billing_operations', [
            'subscription_id' => $order->subscription_id, 'kind' => 'cancel',
            'state' => 'pending', 'last_error' => 'PROVIDER_RESULT_UNRESOLVED',
        ]);
        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $order->subscription_id, 'desired_renewal' => false,
            'renewal_enabled' => true, 'resolution_required' => true,
        ]);
        $this->assertDatabaseCount('billing_notes', 0);
        $notifications = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications', []));
        $this->assertTrue($notifications->contains(fn (array $notification) => $notification['title'] === '付款或订阅正在核对，请稍后刷新'));
        $this->assertFalse($notifications->contains(fn (array $notification) => $notification['status'] === 'success'));
    }

    private function signIn(Role $role): Staff
    {
        $staff = Staff::create(['name' => 'Billing preview test staff', 'email' => BillingStore::id().'@example.test', 'password' => 'TestPassword1234', 'role' => $role]);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->refresh();
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

    private function order(): string
    {
        $id = BillingStore::id();
        DB::table('billing_orders')->insert([
            'id' => $id, 'viewer_id' => BillingStore::id(), 'subscription_id' => BillingStore::id(),
            'idempotency_key' => BillingStore::id(), 'fingerprint' => str_repeat('b', 64), 'transaction_kind' => 'first_subscription',
            'price_version' => 1, 'amount_minor' => 300, 'regular_amount_minor' => 1200, 'discount_applied' => true,
            'currency' => 'USD', 'is_test' => true, 'state' => 'pending', 'payment_state' => 'pending',
            'checkout_id' => 'test_checkout_'.BillingStore::id(), 'checkout_url' => 'https://example.test/private-checkout',
            'return_to' => '/', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function savePreview(string $html): void
    {
        // Static test evidence only: no scripts, session snapshots, or executable form actions.
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html);
        $html = preg_replace('~\s+(?:wire:|x-)[^\s=]+=(?:"[^"]*"|\x27[^\x27]*\x27)~', '', $html);
        $html = str_replace('<button ', '<button disabled ', $html);
        $target = base_path('../.runtime/billing-desk-preview.html');
        File::ensureDirectoryExists(dirname($target));
        File::put($target, '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>账务后台测试渲染</title><style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#172033;margin:32px;max-width:1280px}aside{background:#fef3c7;border:1px solid #d97706;padding:16px;margin-bottom:24px}</style></head><body><aside>只读测试渲染，无真实账务。按钮均已禁用，不是可操作后台。独立测试库中的测试员工和样例价格，不会创建正式账号。</aside>'.$html.'</body></html>');
    }
}
