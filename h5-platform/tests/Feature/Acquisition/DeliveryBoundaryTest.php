<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Sending\DeliveryResult;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\PlatformAdapter;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\DeliveryWorker;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DeliveryBoundaryTest extends AcquisitionTestCase
{
    private function duringPayload(string $platform, \Closure $callback): PlatformAdapter
    {
        $class = $platform === 'meta' ? MetaAdapter::class : TikTokAdapter::class;
        $adapter = new class(new $class, $callback) implements PlatformAdapter
        {
            public int $calls = 0;

            public function __construct(private PlatformAdapter $inner, private \Closure $callback) {}

            public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array
            {
                $body = $this->inner->payload($delivery, $receipt, $context, $testCode);
                ($this->callback)($delivery);

                return $body;
            }

            public function send(object $delivery, array $payload, string $token): DeliveryResult
            {
                $this->calls++;

                return $this->inner->send($delivery, $payload, $token);
            }
        };
        $this->app->instance($class, $adapter);

        return $adapter;
    }

    public function test_lost_or_expired_lease_during_preparation_never_sends_or_changes_the_new_holder(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            foreach ([false, true] as $takeover) {
                [, , , , $delivery] = $this->queueEvent($platform, simulation: false);
                $this->httpCredentials($delivery);
                $expected = null;
                $adapter = $this->duringPayload($platform, function ($row) use ($takeover, &$expected) {
                    $this->travel(61)->seconds();
                    if ($takeover) {
                        DB::table('acquisition_deliveries')->where('id', $row->id)->update([
                            'lease_token' => AcquisitionStore::id(), 'lease_until' => now()->addMinute(),
                            'attempts' => 3, 'first_attempted_at' => now()->subMinutes(5),
                            'payload_encrypted' => Crypt::encryptString('{"new_holder":"frozen"}'),
                        ]);
                    }
                    $expected = (array) DB::table('acquisition_deliveries')->where('id', $row->id)->first();
                });
                $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
                $this->assertSame(0, $adapter->calls);
                $this->assertSame($expected, (array) DB::table('acquisition_deliveries')->where('id', $delivery->id)->first());
                $this->travelBack();
            }
        }
        Http::assertNothingSent();
    }

    public function test_sixth_attempt_is_reserved_with_frozen_payload_and_original_first_time_before_http(): void
    {
        $this->signIn();
        foreach (['meta', 'tiktok'] as $platform) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            [, $link, , , $delivery] = $this->queueEvent($platform, simulation: false);
            $this->httpCredentials($delivery);
            DB::table('acquisition_deliveries')->where('id', $delivery->id)->update([
                'attempts' => 5, 'first_attempted_at' => now()->subMinutes(10),
            ]);
            $first = DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('first_attempted_at');
            Http::fake(function ($request) use ($delivery, $first) {
                $stored = DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();
                $this->assertSame(6, $stored->attempts);
                $this->assertSame($first, $stored->first_attempted_at);
                $this->assertSame($request->body(), Crypt::decryptString($stored->payload_encrypted));

                return Http::response([], 503);
            });
            app(DeliveryWorker::class)->deliver($delivery->id);
            Http::assertSentCount(1);
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'attempts' => 6, 'state' => 'failed']);
            $this->assertSame(0, app(AssetManager::class)->retry($link['id']));
            $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
            $this->assertSame($first, DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('first_attempted_at'));
        }
    }

    public function test_late_response_cannot_overwrite_expired_or_replaced_lease(): void
    {
        $this->signIn();
        foreach ([false, true] as $takeover) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            [, , , , $delivery] = $this->queueEvent(simulation: false);
            $this->httpCredentials($delivery);
            $expected = null;
            Http::fake(function () use ($delivery, $takeover, &$expected) {
                $this->travel(61)->seconds();
                if ($takeover) {
                    DB::table('acquisition_deliveries')->where('id', $delivery->id)->update([
                        'lease_token' => AcquisitionStore::id(), 'lease_until' => now()->addMinute(),
                        'attempts' => 2, 'last_error' => 'NEW_HOLDER_TEST_MARKER',
                    ]);
                }
                $expected = (array) DB::table('acquisition_deliveries')->where('id', $delivery->id)->first();

                return Http::response(['events_received' => 1], 200);
            });
            $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
            $this->assertSame($expected, (array) DB::table('acquisition_deliveries')->where('id', $delivery->id)->first());
            Http::assertSentCount(1);
            $this->travelBack();
        }
    }

    public function test_consent_revoked_during_payload_preparation_blocks_reservation_and_sending(): void
    {
        $this->signIn();
        [$pixel, , $source, , $delivery] = $this->queueEvent(simulation: false);
        $this->httpCredentials($delivery);
        $adapter = $this->duringPayload('meta', fn () => DB::table('acquisition_sources')->where('id', $source->source_id)->update(['revoked_at' => now()]));
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertSame(0, $adapter->calls);
        $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'attempts' => 0, 'first_attempted_at' => null,
            'payload_encrypted' => null, 'state' => 'blocked', 'last_error' => 'CONSENT_REQUIRED_OR_REVOKED']);
        Http::assertNothingSent();
    }
}
