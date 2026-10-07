<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\AttributionService;
use App\Data\PaymentSettled;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\BillingTestCase;

final class BillingBridgeTest extends BillingTestCase
{
    public function test_consumer_failure_leaves_verified_payment_and_membership_intact_and_outbox_retryable(): void
    {
        $this->app->register(AcquisitionServiceProvider::class);
        Http::preventStrayRequests();
        [$viewer] = $this->paidFixture();
        // A failing internal subscriber simulates persistence failure after Acquisition is called.
        Event::listen(PaymentSettled::class, fn () => throw new \RuntimeException('EXPLICIT_TEST_CONSUMER_FAILURE'));
        $this->assertSame(0, app(OutboxService::class)->dispatchPending());
        $this->assertDatabaseHas('billing_outbox', ['delivered_at' => null, 'last_error' => 'CONSUMER_RETRY_REQUIRED']);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertDatabaseCount('acquisition_receipts', 0);
        $this->assertTrue($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        Http::assertNothingSent();
    }

    public function test_real_billing_outbox_reaches_consumer_once_and_unattributed_does_not_block_membership(): void
    {
        $this->app->register(AcquisitionServiceProvider::class);
        Http::preventStrayRequests();
        [$viewer, $order, $sub, $invoiceId] = $this->paidFixture();
        $this->assertNull(app(AttributionService::class)->snapshot(null));
        $outbox = app(OutboxService::class);
        $this->assertSame(1, $outbox->dispatchPending());
        $this->assertSame(0, $outbox->dispatchPending());
        app(SettlementService::class)->settleInvoice($invoiceId);
        $this->assertSame(0, $outbox->dispatchPending());
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->assertDatabaseHas('acquisition_receipts', ['source_status' => 'unattributed', 'amount_minor' => 300]);
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('billing_entitlements', 1);
        $this->assertTrue($this->engine->membership($viewer->id, now()->toDateTimeImmutable())->access_active);
        $this->assertSame(0, app(DeliveryWorker::class)->run());
        Http::assertNothingSent();
    }
}
