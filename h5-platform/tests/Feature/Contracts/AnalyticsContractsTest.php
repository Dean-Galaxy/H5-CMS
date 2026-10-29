<?php

namespace Tests\Feature\Contracts;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Support\Analytics\BehaviorEventCatalog;
use App\Support\DependencyNotReady;
use App\Support\DisabledBehaviorEventRecorder;
use App\Support\UnavailableBillingAnalyticsReader;
use App\Enums\BehaviorRecordingResult;
use Tests\TestCase;

class AnalyticsContractsTest extends TestCase
{
    public function test_disabled_recorder_is_safe_for_existing_business_without_a_consumer(): void
    {
        $this->assertInstanceOf(BehaviorEventRecorder::class, app(BehaviorEventRecorder::class));
        $disabled = new DisabledBehaviorEventRecorder;
        $this->assertFalse($disabled->record('payment_confirmed', ['payment_id' => 'untrusted'], 'same-key'));
        $disabled->clearBrowserContext();
        $this->assertFalse($disabled->record('registration_succeeded'));
        $this->assertSame(BehaviorRecordingResult::Retry, $disabled->recordFact('refund_confirmed', [], 'same-refund'));
    }

    public function test_missing_financial_reader_does_not_fabricate_zero_revenue(): void
    {
        $this->assertInstanceOf(BillingAnalyticsReader::class, app(BillingAnalyticsReader::class));
        $this->expectException(DependencyNotReady::class);
        (new UnavailableBillingAnalyticsReader)->payments([]);
    }

    public function test_browser_allowlist_cannot_produce_authoritative_success_events(): void
    {
        $this->assertSame([], array_intersect(BehaviorEventCatalog::BROWSER_EVENTS, BehaviorEventCatalog::SERVER_EVENTS));
        foreach (['payment_confirmed', 'refund_confirmed', 'registration_succeeded', 'email_verified'] as $event) {
            $this->assertContains($event, BehaviorEventCatalog::SERVER_EVENTS);
            $this->assertNotContains($event, BehaviorEventCatalog::BROWSER_EVENTS);
        }
        $all = [...BehaviorEventCatalog::BROWSER_EVENTS, ...BehaviorEventCatalog::SERVER_EVENTS];
        $this->assertSame($all, array_values(array_unique($all)));
        $this->assertSame('analytics-v1', BehaviorEventCatalog::POLICY_VERSION);
    }
}
