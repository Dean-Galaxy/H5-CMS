<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Billing\Filament\Pages\BillingDesk;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Staff;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
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

    public function test_owner_renders_explicit_sample_prices_and_saved_version_survives_refresh(): void
    {
        $this->signIn(Role::Owner);
        $this->assertDatabaseCount('billing_prices', 0);
        $page = Livewire::test(BillingDesk::class)
            ->assertOk()
            ->assertSee('Stripe 未配置；官方测试未验证')
            ->assertSee('仅是测试样例，尚非最终售价')
            ->assertSee('首次保存前没有生效价格')
            ->assertSee('负责人：新订阅价格')
            ->assertSet('price.version', 0)
            ->assertSet('price.is_test', true);
        $this->assertDatabaseCount('billing_prices', 0);
        $page->set('price.regular_amount_minor', 1500)
            ->set('price.first_week_amount_minor', 500)
            ->call('savePrice')
            ->assertHasNoErrors()
            ->assertSet('price.version', 1)
            ->assertSee('版本：1');
        Notification::assertNotified('测试价格新版本已保存，原订阅约定价保留');
        $this->assertDatabaseHas('billing_prices', ['version' => 1, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'is_test' => true]);
        $page = Livewire::test(BillingDesk::class)
            ->assertOk()
            ->assertSet('price.version', 1)
            ->assertSet('price.regular_amount_minor', 1500)
            ->assertSet('price.first_week_amount_minor', 500)
            ->assertSee('版本：1');
        $this->savePreview($page->html(true));
    }

    public function test_support_can_open_exact_order_without_price_controls_or_payment_credentials(): void
    {
        $support = $this->signIn(Role::Support);
        $orderId = $this->order();
        $page = Livewire::test(BillingDesk::class)
            ->assertOk()
            ->assertSee('Stripe 未配置；官方测试未验证')
            ->assertDontSee('负责人：新订阅价格')
            ->assertDontSee('保存新的测试价格版本')
            ->assertDontSee('负责人：最近 50 笔站内订单')
            ->set('orderId', $orderId)
            ->call('openCase')
            ->assertHasNoErrors()
            ->assertSee($orderId)
            ->assertSee('订单与会员核实结果')
            ->assertSee('测试订单')
            ->assertDontSee('https://example.test/private-checkout');
        $caseId = $page->get('caseId');
        $this->assertDatabaseHas('billing_cases', ['id' => $caseId, 'order_id' => $orderId, 'staff_id' => $support->id]);
        $page->set('note', 'Testing only: identity and cancellation request still need verification.')
            ->call('addNote')->assertHasNoErrors()
            ->assertSee('Testing only: identity and cancellation request still need verification.');
        $this->assertDatabaseCount('billing_notes', 1);
        $page->call('savePrice')->assertOk();
        Notification::assertNotified('无权执行此操作');
        $this->assertDatabaseCount('billing_prices', 0);
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
