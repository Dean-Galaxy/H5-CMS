<?php

namespace Tests\Integration;

use App\Data\ResumeInput;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Billing\Services\BillingStore;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\ContentTestCase;
use Tests\Integration\Support\PaymentScenario;

final class EntitlementPlaybackTest extends ContentTestCase
{
    public function test_audience_progress_survives_refund_but_media_and_renewal_are_revoked(): void
    {
        Http::preventStrayRequests();
        $this->app->register(AcquisitionServiceProvider::class);
        $d = $this->fixture(6, 6, true);
        $p = PaymentScenario::paid();
        $this->asViewer($p['viewer']);
        $episode = $d->episodes[5];
        $path = '/audience/dramas/'.$d->id.'/episodes/'.$episode->id.'/playback';
        $grant = $this->postJson($path, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', true)->json();
        $this->get($grant['short_lived_url'])->assertOk();
        $progress = '/api/v1/me/progress/'.$d->id;
        $this->putJson($progress, ['episode_id' => $episode->id, 'position_seconds' => 3, 'play_session_id' => $grant['play_session_id'], 'sequence' => 1])->assertOk();
        app(OutboxService::class)->dispatchPending();
        app(SettlementService::class)->settleInvoice($p['iid']);
        app(OutboxService::class)->dispatchPending();
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $p['gateway']->refunds[$p['charge']] = [['id' => 're_06', 'amount_minor' => 300, 'status' => 'succeeded', 'currency' => 'USD']];
        app(SettlementService::class)->syncRefunds($p['charge']);
        $this->get($grant['short_lived_url'])->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_REQUIRED');
        $this->postJson($path, ['play_session_id' => $grant['play_session_id']])->assertOk()->assertJsonPath('allowed', false);
        $this->getJson($progress)->assertOk()->assertJsonPath('position_seconds', 3);
        $this->postJson('/audience/dramas/'.$d->id.'/episodes/'.$d->episodes[4]->id.'/playback', ['play_session_id' => null])->assertOk()->assertJsonPath('reason', 'allowed_free');
        $this->assertFalse($p['engine']->membership($p['viewer']->id, now()->toDateTimeImmutable())->renewal_enabled);
        $this->assertSame(0, app(DeliveryWorker::class)->run());
        Http::assertNothingSent();
    }

    public function test_original_price_resume_and_unpaid_expiry_reach_audience_authorization(): void
    {
        $d = $this->fixture(6, 6, true);
        $p = PaymentScenario::paid();
        $this->asViewer($p['viewer']);
        $before = $p['engine']->cancel($p['identity']);
        $this->assertSame(BillingStore::iso($p['end']), $before->access_until);
        DB::table('billing_prices')->insert(['id' => BillingStore::id(), 'version' => 2, 'regular_amount_minor' => 1500, 'first_week_amount_minor' => 500, 'currency' => 'USD', 'is_test' => true, 'created_at' => now()]);
        $quote = $p['engine']->resumeQuote($p['identity']);
        $this->assertSame(1200, $quote->next_renewal_amount_minor);
        $resumed = $p['engine']->resume($p['identity'], new ResumeInput($quote->quote_id, $quote->confirmation_version, $quote->next_renewal_at, 1200, 'USD'));
        $this->assertSame($before->access_until, $resumed->access_until);
        $this->assertDatabaseCount('billing_payments', 1);
        $p['gateway']->subscriptions[$p['sid']]['status'] = 'past_due';
        $this->travelTo($p['end']);
        $path = '/audience/dramas/'.$d->id.'/episodes/'.$d->episodes[5]->id.'/playback';
        $this->postJson($path, ['play_session_id' => null])->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'MEMBERSHIP_REQUIRED');
        $this->assertDatabaseCount('billing_entitlements', 1);
    }
}
