<?php

namespace Tests\Feature\Acquisition;

use App\Data\AttributionSnapshot;
use App\Data\PaymentSettled;
use App\Enums\TransactionKind;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PaymentTest extends AcquisitionTestCase
{
    public function test_event_and_payment_deduplication_preserve_one_receipt_and_one_conversion(): void
    {
        $this->signIn();
        [$pixel, $link, $source, $event] = $this->queueEvent();
        $receiver = app(PaymentReceiver::class);
        $receiver->handle($event);
        $receiver->handle(PaymentSettled::fromArray(array_replace($event->toArray(), ['event_id' => AcquisitionStore::id()])));
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_deliveries', 1);
        $this->assertDatabaseCount('acquisition_event_ids', 2);
        $summary = app(AssetManager::class)->summary($link['id']);
        $this->assertSame(300, $summary['payments'][0]['amount_minor']);
        $this->assertSame(1, $summary['payments'][0]['payments']);
        $this->assertStringNotContainsString($event->payment_id, json_encode($summary));
        $this->assertStringNotContainsString($event->order_id, json_encode($summary));
        Http::assertNothingSent();
    }

    public function test_same_payment_with_changed_facts_is_rejected_without_overwriting_history(): void
    {
        $this->signIn();
        [, , , $event] = $this->queueEvent();
        try {
            app(PaymentReceiver::class)->handle(PaymentSettled::fromArray(array_replace($event->toArray(), ['event_id' => AcquisitionStore::id(), 'amount_minor' => 1500])));
            $this->fail('conflicting amount accepted');
        } catch (\DomainException $e) {
            $this->assertSame('ACQUISITION_PAYMENT_CONFLICT', $e->getMessage());
        }
        $this->assertDatabaseHas('acquisition_receipts', ['payment_id' => $event->payment_id, 'amount_minor' => 300]);
        try {
            app(PaymentReceiver::class)->handle(PaymentSettled::fromArray(array_replace($event->toArray(), ['payment_id' => AcquisitionStore::id()])));
            $this->fail('event id reused');
        } catch (\DomainException $e) {
            $this->assertSame('ACQUISITION_EVENT_CONFLICT', $e->getMessage());
        }
        $this->assertDatabaseCount('acquisition_receipts', 1);
    }

    public function test_unknown_source_is_not_reinterpreted_as_current_pixel_and_no_source_is_allowed(): void
    {
        $this->signIn();
        [$pixel, $link] = $this->assets();
        $forged = new AttributionSnapshot(AcquisitionStore::id(), $link['id'], 'meta', 1, now()->toIso8601String(), 'granted');
        $receiver = app(PaymentReceiver::class);
        $receiver->handle($this->event($forged));
        $receiver->handle($this->event());
        $this->assertDatabaseCount('acquisition_deliveries', 0);
        $this->assertDatabaseHas('acquisition_receipts', ['source_status' => 'unresolved_source', 'link_id' => null]);
        $this->assertDatabaseHas('acquisition_receipts', ['source_status' => 'unattributed', 'link_id' => null]);
        $this->assertSame([], app(AssetManager::class)->summary($link['id'])['payments']);
    }

    public function test_first_resubscription_renewal_and_test_real_are_separate_but_same_kind_not_double_counted(): void
    {
        $this->signIn();
        [$pixel, $link] = $this->assets();
        $source = $this->source($pixel, $link);
        foreach (TransactionKind::cases() as $kind) {
            foreach ([true, false] as $test) {
                $event = $this->event($source, $kind, $test);
                app(PaymentReceiver::class)->handle($event);
                app(PaymentReceiver::class)->handle($event);
            }
        }
        $rows = app(AssetManager::class)->summary($link['id'])['payments'];
        $this->assertCount(6, $rows);
        $this->assertSame(6, array_sum(array_column($rows, 'payments')));
        $this->assertSame(5400, array_sum(array_column($rows, 'amount_minor')));
    }

    public function test_pixel_and_link_edits_do_not_rewrite_already_received_attribution_or_destination(): void
    {
        $this->signIn();
        [$pixel, $link, $source, $event, $delivery] = $this->queueEvent();
        $before = DB::table('acquisition_receipts')->first()->attribution_snapshot;
        app(AssetManager::class)->savePixel($pixel['id'], array_replace(array_intersect_key($pixel, $this->pixelInput()), ['name' => 'New name']));
        app(PaymentReceiver::class)->handle($event);
        $this->assertSame($before, DB::table('acquisition_receipts')->first()->attribution_snapshot);
        $this->assertSame(1, DB::table('acquisition_deliveries')->first()->pixel_version);
        $this->assertSame($delivery->external_id, DB::table('acquisition_deliveries')->first()->external_id);
    }
}
