<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\AttributionService;
use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Billing\Services\OutboxService;
use App\Modules\Billing\Services\SettlementService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\BillingTestCase;

final class BillingBridgeTest extends BillingTestCase
{
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
