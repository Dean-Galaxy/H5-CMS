<?php

namespace Tests\Integration\AnalyticsCombined;

use App\Modules\Billing\Services\OutboxService;
use App\Support\Analytics\BehaviorEventCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Integration\AnalyticsCombined\Support\CombinedTestCase;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;

/** These exercise actual consumers once received. PHP requests do NOT prove real-HTTP CSRF handling. */
final class PrivacyApiTest extends CombinedTestCase
{
    public function test_server_events_and_client_authority_properties_cannot_fabricate_success(): void
    {
        $this->consent();
        foreach (BehaviorEventCatalog::SERVER_EVENTS as $name) {
            $this->postJson('/api/v1/tracking/events', ['events' => [['event_id' => (string) Str::uuid(),
                'event_name' => $name, 'properties' => []]]])->assertOk()->assertJsonPath('accepted', 0);
        }
        foreach (['viewer_id' => (string) Str::uuid(), 'source_id' => (string) Str::uuid(), 'amount_minor' => 999,
            'country' => 'US', 'play_session_id' => (string) Str::uuid(), 'token' => 'SYNTHETIC_PRIVATE'] as $key => $value) {
            $this->postJson('/api/v1/tracking/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view',
                'properties' => ['page_instance_id' => (string) Str::uuid(), 'path' => '/', $key => $value]]]])
                ->assertOk()->assertJsonPath('accepted', 0);
        }
        $this->assertDatabaseCount('acquisition_behaviors', 0); $this->assertDatabaseCount('billing_payments', 0);
        Http::assertNothingSent();
    }

    public function test_same_uuid_retries_are_idempotent_but_different_content_is_rejected(): void
    {
        $this->consent();
        $event = $this->browser('page_view');
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
        $event['properties']['path'] = '/catalog';
        $this->postJson('/api/v1/tracking/events', ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 0);
        $this->assertDatabaseCount('acquisition_behaviors', 1); $this->assertDatabaseCount('acquisition_event_keys', 1);
    }

    public function test_collection_storage_failure_does_not_undo_paid_access_and_reliable_payment_retries(): void
    {
        $this->consent(); $this->landing(); $this->registerAndVerify(); $order = $this->checkout();
        $before = DB::table('acquisition_behaviors')->count();
        DB::statement('ALTER TABLE acquisition_behaviors RENAME TO acquisition_behaviors_06_unavailable');
        try {
            $payment = ControlledPayment::settle($order->id, $this->gateway);
            app(OutboxService::class)->dispatchPending();
            $this->assertDatabaseCount('billing_payments', 1); $this->assertDatabaseCount('billing_entitlements', 1);
            $this->postJson('/audience/dramas/'.$this->drama->id.'/episodes/'.$this->drama->episodes[5]->id.'/playback', ['play_session_id' => null])
                ->assertOk()->assertJsonPath('allowed', true);
            $this->assertNull(DB::table('billing_outbox')->where('payment_id', $payment['payment_id'])->sole()->delivered_at);
        } finally {
            DB::statement('ALTER TABLE acquisition_behaviors_06_unavailable RENAME TO acquisition_behaviors');
        }
        app(OutboxService::class)->dispatchPending(); app(OutboxService::class)->dispatchPending();
        $this->assertSame($before + 1, DB::table('acquisition_behaviors')->count());
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_denial_and_cross_origin_requests_do_not_create_behavior_or_financial_facts(): void
    {
        $this->consent(false, false);
        $this->postJson('/api/v1/tracking/events', ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view',
            'properties' => ['page_instance_id' => (string) Str::uuid(), 'path' => '/']]]])->assertOk()->assertJsonPath('accepted', 0);
        $this->withHeader('Origin', 'https://attacker.invalid')->postJson('/api/v1/tracking/consent', [
            'analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertForbidden();
        $this->assertDatabaseCount('acquisition_behaviors', 0); $this->assertDatabaseCount('billing_payments', 0);
        Http::assertNothingSent();
    }
}
