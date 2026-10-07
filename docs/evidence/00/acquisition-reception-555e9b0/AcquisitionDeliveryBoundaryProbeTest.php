<?php

namespace Tests\Feature\Contracts;

use App\Modules\Acquisition\Sending\DeliveryResult;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\PlatformAdapter;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\DeliveryWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Acquisition\AcquisitionTestCase;

final class AcquisitionDeliveryBoundaryProbeTest extends AcquisitionTestCase
{
    public function test_lost_lease_before_io_does_not_call_the_transport(): void
    {
        $this->signIn();
        [, , , , $delivery] = $this->queueEvent(simulation: false);
        $this->httpCredentials($delivery);
        $newToken = AcquisitionStore::id();
        $adapter = new class($newToken, fn () => $this->travel(61)->seconds()) implements PlatformAdapter
        {
            public int $calls = 0;

            public function __construct(private string $newToken, private \Closure $expireLease) {}

            public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array
            {
                // Deterministic interleaving: original process paused; lease expired;
                // another worker acquired it before the old process resumes pre-I/O.
                ($this->expireLease)();
                DB::table('acquisition_deliveries')->where('id', $delivery->id)->update([
                    'lease_token' => $this->newToken, 'lease_until' => now()->addMinute(),
                ]);

                return ['fixture' => true];
            }

            public function send(object $delivery, array $payload, string $token): DeliveryResult
            {
                $this->calls++;

                return new DeliveryResult('api_accepted');
            }
        };
        $this->app->instance(MetaAdapter::class, $adapter);
        app(DeliveryWorker::class)->deliver($delivery->id);
        $this->assertSame(0, $adapter->calls, 'A worker whose lease writes failed must not send.');
    }

    public function test_attempt_is_persisted_before_the_sixth_network_call(): void
    {
        $this->signIn();
        [, , , , $delivery] = $this->queueEvent(simulation: false);
        $this->httpCredentials($delivery);
        DB::table('acquisition_deliveries')->where('id', $delivery->id)->update([
            'attempts' => 5, 'first_attempted_at' => now()->subMinutes(10),
        ]);
        $observedAttempts = null;
        Http::fake(function () use ($delivery, &$observedAttempts) {
            $observedAttempts = (int) DB::table('acquisition_deliveries')->where('id', $delivery->id)->value('attempts');

            return Http::response([], 503);
        });
        app(DeliveryWorker::class)->deliver($delivery->id);
        Http::assertSentCount(1);
        $this->assertSame(6, $observedAttempts, 'A process dying during I/O must not lose the reserved attempt.');
    }
}
