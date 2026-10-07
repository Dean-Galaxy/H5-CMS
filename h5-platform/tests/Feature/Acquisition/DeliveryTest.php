<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\DeliveryWorker;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DeliveryTest extends AcquisitionTestCase
{
    public function test_both_platforms_have_explicit_local_sending_and_no_external_requests(): void
    {
        $this->signIn();
        config(['acquisition.transport' => 'local_simulation']);
        foreach (['meta', 'tiktok'] as $platform) {
            [, , , , $delivery] = $this->queueEvent($platform);
            $worker = app(DeliveryWorker::class);
            $this->assertTrue($worker->deliver($delivery->id));
            $this->assertFalse($worker->deliver($delivery->id));
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'simulated', 'attempts' => 1]);
            $this->assertDatabaseHas('acquisition_simulations', ['event_key' => $delivery->event_key, 'platform' => $platform, 'calls' => 1]);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_sender_and_unknown_or_revoked_consent_block_real_attempts(): void
    {
        $this->signIn();
        [, $link, $source, , $delivery] = $this->queueEvent();
        $worker = app(DeliveryWorker::class);
        $worker->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'blocked', 'last_error' => 'EXTERNAL_DISABLED', 'attempts' => 0]);
        foreach (['unknown', 'denied', 'granted'] as $choice) {
            config(['acquisition.transport' => 'local_simulation']);
            DB::table('acquisition_sources')->where('id', $source->source_id)->update(['consent' => $choice, 'revoked_at' => $choice === 'granted' ? now() : null]);
            app(AssetManager::class)->retry($link['id']);
            $worker->deliver($delivery->id);
            $this->assertDatabaseHas('acquisition_deliveries', ['state' => 'blocked', 'last_error' => 'CONSENT_REQUIRED_OR_REVOKED', 'attempts' => 0]);
        }
        Http::assertNothingSent();
    }

    public function test_test_payments_can_never_be_sent_to_production_assets(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            [, , , , $delivery] = $this->queueEvent($platform, 'production');
            config(['acquisition.transport' => 'local_simulation']);
            app(DeliveryWorker::class)->deliver($delivery->id);
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'blocked', 'last_error' => 'PAYMENT_ASSET_ENVIRONMENT_MISMATCH', 'attempts' => 0]);
        }
        Http::assertNothingSent();
    }

    public function test_http_adapters_map_integer_usd_stable_ids_and_do_not_send_contact_or_order_data(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            [$pixel, , , $event, $delivery] = $this->queueEvent($platform, simulation: false);
            $this->httpCredentials($delivery);
            Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
                'business-api.tiktok.com/*' => Http::response(['code' => 0, 'request_id' => 'testing-only'], 200)]);
            app(DeliveryWorker::class)->deliver($delivery->id);
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'api_accepted', 'attempts' => 1]);
            Http::assertSent(function ($request) use ($platform, $delivery, $event) {
                $body = $request->data();
                $data = $body['data'][0];
                $this->assertSame($delivery->event_key, $data['event_id']);
                $this->assertSame('Purchase', $data[$platform === 'meta' ? 'event_name' : 'event']);
                $this->assertIsInt($data['event_time']);
                $money = $data[$platform === 'meta' ? 'custom_data' : 'properties'];
                $this->assertEquals(3, $money['value']);
                $this->assertSame('USD', $money['currency']);
                $this->assertSame('first_subscription', $money['transaction_kind']);
                $this->assertSame('UNIT_TEST', $body['test_event_code']);
                $this->assertStringNotContainsString('must-not-send', json_encode($body));
                $this->assertStringNotContainsString($event->order_id, json_encode($body));
                $this->assertStringNotContainsString('UNIT-TEST-NOT-A-REAL-SECRET', $request->url());

                return true;
            });
        }
    }

    public function test_failed_retry_uses_frozen_payload_and_stable_event_id_then_stops_after_acceptance(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            [$pixel, $link, $source, , $delivery] = $this->queueEvent($platform, simulation: false);
            $this->httpCredentials($delivery);
            Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'secret-not-to-save']], 503)
                ->push($platform === 'meta' ? ['events_received' => 1] : ['code' => 0], 200)]);
            $worker = app(DeliveryWorker::class);
            $worker->deliver($delivery->id);
            $row = DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();
            $this->assertSame('retry', $row->state);
            $frozen = Crypt::decryptString($row->payload_encrypted);
            $this->assertFalse($worker->deliver($delivery->id));
            app(AssetManager::class)->retry($link['id']);
            $worker->deliver($delivery->id);
            $row = DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();
            $this->assertSame('api_accepted', $row->state);
            $this->assertSame(2, $row->attempts);
            $this->assertSame($frozen, Crypt::decryptString($row->payload_encrypted));
            $requests = Http::recorded();
            // Compare the bytes actually sent, not PHP object identity after JSON restoration.
            $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
            $this->assertSame(0, app(AssetManager::class)->retry($link['id']));
            Http::assertSentCount(2);
        }
    }

    public function test_http_200_errors_and_redirects_are_not_marked_accepted(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            [, , , , $delivery] = $this->queueEvent($platform, simulation: false);
            $this->httpCredentials($delivery);
            Http::fake(['*' => Http::response($platform === 'meta' ? ['error' => ['code' => 190]] : ['code' => 40001], 200)]);
            app(DeliveryWorker::class)->deliver($delivery->id);
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'failed']);
        }
    }

    public function test_simulated_context_missing_credentials_and_disabled_pixel_are_blocked(): void
    {
        $this->signIn();
        [$pixel, $link, $source, , $delivery] = $this->queueEvent();
        $this->httpCredentials($delivery);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['last_error' => 'SIMULATED_SOURCE_EXTERNAL_FORBIDDEN']);
        DB::table('acquisition_sources')->where('id', $source->source_id)->update(['is_simulation' => false]);
        config(['acquisition.credentials_file' => '']);
        app(AssetManager::class)->retry($link['id']);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['last_error' => 'CREDENTIALS_REQUIRED']);
        DB::table('acquisition_pixels')->where('id', $pixel['id'])->update(['active' => false]);
        app(AssetManager::class)->retry($link['id']);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertDatabaseHas('acquisition_deliveries', ['last_error' => 'PIXEL_DISABLED', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_active_lease_prevents_duplicate_sending_and_expired_lease_can_recover(): void
    {
        $this->signIn();
        [, , , , $delivery] = $this->queueEvent();
        config(['acquisition.transport' => 'local_simulation']);
        DB::table('acquisition_deliveries')->where('id', $delivery->id)->update(['state' => 'sending', 'lease_token' => AcquisitionStore::id(), 'lease_until' => now()->addMinute()]);
        $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
        $this->travel(61)->seconds();
        $this->assertTrue(app(DeliveryWorker::class)->deliver($delivery->id));
        $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'simulated', 'attempts' => 1]);
    }

    public function test_retry_limit_and_production_simulation_boot_guard(): void
    {
        $this->signIn();
        [, , , , $delivery] = $this->queueEvent();
        DB::table('acquisition_deliveries')->where('id', $delivery->id)->update(['attempts' => 6]);
        $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
        $this->assertDatabaseHas('acquisition_deliveries', ['last_error' => 'RETRY_EXHAUSTED', 'state' => 'failed']);
        $this->app->instance('env', 'production');
        config(['acquisition.transport' => 'local_simulation']);
        $this->expectException(\LogicException::class);
        (new AcquisitionServiceProvider($this->app))->boot();
    }
}
