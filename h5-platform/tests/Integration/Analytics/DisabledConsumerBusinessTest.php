<?php

namespace Tests\Integration\Analytics;

use App\Contracts\BehaviorEventRecorder;
use App\Enums\BehaviorRecordingResult;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use App\Support\DisabledBehaviorEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\ContentTestCase;
use Tests\Integration\Support\PaymentScenario;

/** Preparation only: real local persistence with synthetic gateway responses, NOT consumer acceptance. */
final class DisabledConsumerBusinessTest extends ContentTestCase
{
    public function test_unavailable_analytics_does_not_block_payment_entitlement_or_refund(): void
    {
        Http::preventStrayRequests();
        $this->app->register(AcquisitionServiceProvider::class);
        $this->app->instance(BehaviorEventRecorder::class, new DisabledBehaviorEventRecorder);
        config(['acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]);
        $p = PaymentScenario::paid();
        $this->assertTrue($p['engine']->membership($p['viewer']->id, now()->toDateTimeImmutable())->access_active);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        app(OutboxService::class)->dispatchPending();
        // Retried settlement must not add a payment; local contract facts are not official Stripe evidence.
        app(SettlementService::class)->settleInvoice($p['iid']);
        app(OutboxService::class)->dispatchPending();
        $this->assertDatabaseCount('billing_payments', 1);
        $p['gateway']->refunds[$p['charge']] = [['id' => 're_analytics06', 'amount_minor' => 300,
            'status' => 'succeeded', 'currency' => 'USD']];
        app(SettlementService::class)->syncRefunds($p['charge']);
        $this->assertSame(300, (int) DB::table('billing_payments')->value('refunded_amount_minor'));
        $this->assertSame(0, app(DeliveryWorker::class)->run());
        Http::assertNothingSent();
    }

    public function test_missing_recorder_never_claims_accepted_or_skipped_reliable_facts(): void
    {
        $recorder = new DisabledBehaviorEventRecorder;
        $this->assertFalse($recorder->record('page_view'));
        foreach (['payment_confirmed', 'refund_confirmed', 'subscription_cancelled'] as $name) {
            $this->assertSame(BehaviorRecordingResult::Retry, $recorder->recordFact($name, [], '06-same-fact'));
            $this->assertSame(BehaviorRecordingResult::Retry, $recorder->recordFact($name, [], '06-same-fact'));
        }
        $recorder->clearBrowserContext();
        $this->assertFalse($recorder->record('logout'));
    }

    public function test_disabled_collection_does_not_turn_an_unpaid_viewer_into_a_member(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(BehaviorEventRecorder::class, new DisabledBehaviorEventRecorder);
        $d = $this->fixture(6, 6, true);
        $base = '/audience/dramas/'.$d->id.'/episodes/';
        $this->postJson($base.$d->episodes[0]->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', true);
        $this->postJson($base.$d->episodes[5]->id.'/playback', ['play_session_id' => null])
            ->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'MEMBERSHIP_REQUIRED');
        $this->assertDatabaseCount('billing_payments', 0);
        Http::assertNothingSent();
    }
}
