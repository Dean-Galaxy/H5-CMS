<?php

namespace Tests\Integration;

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use App\Enums\Role;
use App\Modules\Billing\Services\BillingEngine;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Identity\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Integration\Support\PaymentScenario;
use Tests\Integration\Support\RecoveryScenario;
use Tests\TestCase;

final class RecoveryScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->bind(RegionResolver::class, fn () => new class implements RegionResolver
        {
            public function resolve(): ResolvedRegion
            {
                return new ResolvedRegion('US', 'local_us_test');
            }
        });
    }

    public function test_preexisting_unpaid_accounts_do_not_select_the_recovery_subject(): void
    {
        $unpaid = RecoveryScenario::prepareUnpaidAccounts();
        $existingStaff = Staff::create(['name' => 'Existing without MFA', 'email' => BillingStore::id().'@example.test',
            'password' => bin2hex(random_bytes(24)), 'role' => Role::Owner]);
        $firstAccount = DB::table('billing_accounts')->first()->viewer_id;
        $seed = RecoveryScenario::seed();
        $ids = $seed['manifest']['targets'];
        $this->assertNotSame($firstAccount, $ids['viewer_id']);
        $this->assertNotContains($ids['viewer_id'], $unpaid);
        $this->assertNotSame($existingStaff->id, $ids['staff_id']);
        $this->assertNull($existingStaff->refresh()->getAppAuthenticationSecret());
        $this->assertTrue($seed['summary']['access_active']);
        $this->assertFalse($seed['summary']['renewal_enabled']);
        foreach (['orders', 'payments', 'entitlements', 'refunds', 'outbox'] as $key) {
            $this->assertSame(1, $seed['summary'][$key]);
        }
        $this->assertSame(3, $seed['summary']['unrelated_accounts']);
        $this->assertTrue($seed['summary']['unrelated_accounts_unchanged']);
        foreach ($unpaid as $id) {
            $this->assertFalse(app(BillingEngine::class)->membership($id, now()->toDateTimeImmutable())->access_active);
            $this->assertDatabaseHas('billing_accounts', ['viewer_id' => $id, 'first_paid_at' => null, 'first_offer_used_at' => null]);
            $this->assertDatabaseMissing('billing_payments', ['viewer_id' => $id]);
        }

        // Persist/deserialize the exact manifest as the separate restore process does.
        // Real PostgreSQL dump/restore/restart is exercised by backup_restore.py, not claimed here.
        $manifest = json_decode(json_encode($seed['manifest'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($seed['summary'], RecoveryScenario::verify($manifest));
        $this->artisan('content:publish-due')->assertSuccessful();
        $after = RecoveryScenario::verify($manifest);
        $this->assertSame($ids, $after['targets']);
        $this->assertSame('missed_pending_policy', $after['target_plan_state']);
        $this->assertSame('draft', $after['target_drama_state']);
        $this->assertSame(['held'], $after['target_publication_results']);
        $this->assertSame($seed['summary']['published_publications'], $after['published_publications']);
        $this->assertSame($seed['summary']['public_episodes'], $after['public_episodes']);
        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame($after, RecoveryScenario::verify($manifest));
        Http::assertNothingSent();
    }

    public function test_manifest_cannot_switch_to_an_existing_unpaid_viewer(): void
    {
        $unpaid = RecoveryScenario::prepareUnpaidAccounts();
        $seed = RecoveryScenario::seed();
        $seed['manifest']['targets']['viewer_id'] = $unpaid[0];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Recovery target is a preexisting unrelated viewer.');
        RecoveryScenario::verify($seed['manifest']);
    }

    public function test_a_payment_for_another_viewer_is_rejected_even_when_that_payment_exists(): void
    {
        $seed = RecoveryScenario::seed();
        $other = PaymentScenario::paid();
        $otherPayment = DB::table('billing_payments')->where('order_id', $other['order']->id)->sole();
        $seed['manifest']['targets']['payment_id'] = $otherPayment->id;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Recovery target mismatch: payment.viewer_id');
        RecoveryScenario::verify($seed['manifest']);
    }

    public function test_changes_to_preexisting_unpaid_accounts_fail_verification(): void
    {
        $unpaid = RecoveryScenario::prepareUnpaidAccounts();
        $seed = RecoveryScenario::seed();
        DB::table('billing_accounts')->where('viewer_id', $unpaid[1])->update(['first_offer_used_at' => now()]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unrelated recovery accounts changed.');
        RecoveryScenario::verify($seed['manifest']);
    }
}
