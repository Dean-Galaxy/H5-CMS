<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\BillingAnalyticsReader;
use App\Contracts\CatalogReader;
use App\Data\Drama;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;

final class ClickMatchingTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['acquisition.analytics_enabled' => true, 'app.url' => 'https://localhost']);
        $this->signIn($this->staff(Role::Owner));
        $id = array_key_first($this->dramas);
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->withAnyArgs()->andReturn(new Drama($id, 'Verified fixture title', '', null, 0, 0, 5, 'published', []));
        $this->app->instance(CatalogReader::class, $catalog);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeader('User-Agent', 'Mozilla/5.0 Explicit local matching fixture');
    }

    private function landing(array $link, array $click = []): array
    {
        return ['page_instance_id' => (string) Str::uuid(), 'drama_id' => $link['drama_id'], 'path' => '/dramas/'.$link['drama_id'], 'click_context' => $click];
    }

    public function test_meta_and_dotted_tiktok_url_to_encrypted_source_to_fake_http_payload_are_platform_isolated(): void
    {
        foreach (['meta', 'tiktok'] as $platform) {
            app(TrackingContext::class)->clear();
            [$pixel, $link] = $this->assets($platform);
            $key = $platform === 'meta' ? 'fbclid' : 'ttclid';
            $click = $platform === 'meta' ? 'EXPLICIT_LOCAL_CLICK_meta' : 'E.C.P.SYNTHETIC_06-CLICK';
            $this->get($link['url'].'?'.http_build_query(['email' => 'hidden@example.test', 'fbclid' => 'EXPLICIT_LOCAL_CLICK_meta', 'ttclid' => 'E.C.P.SYNTHETIC_06-CLICK']))
                ->assertRedirect('/dramas/'.$link['drama_id'].'?'.$key.'='.$click);
            $this->assertDatabaseCount('acquisition_sources', $platform === 'meta' ? 0 : 1);
            $this->assertStringNotContainsString($click, json_encode(session('tracking')));
            $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
            $body = $this->landing($link, ['fbclid' => 'EXPLICIT_LOCAL_CLICK_meta', 'ttclid' => 'E.C.P.SYNTHETIC_06-CLICK', 'fbc' => 'fb.1.1700000000000.OLD_CLICK', 'fbp' => 'fb.1.1700000000000.123456', 'ttp' => 'TEST_TTP-123']);
            $this->postJson('/api/v1/tracking/landing', $body)->assertOk()->assertJsonPath('confirmed', true);
            $before = app(AttributionRecorder::class)->snapshot(null);
            $this->travel(2)->seconds();
            $this->postJson('/api/v1/tracking/landing', $body)->assertJsonPath('confirmed', true);
            $this->assertSame($before->captured_at, app(AttributionRecorder::class)->snapshot(null)->captured_at);
            $source = DB::table('acquisition_sources')->find($before->source_id);
            $this->assertStringNotContainsString($click, $source->context_encrypted);
            $context = json_decode(Crypt::decryptString($source->context_encrypted), true);
            $this->assertSame('https://localhost/dramas/'.$link['drama_id'], $context['page_url']);
            if ($platform === 'meta') {
                $this->assertStringEndsWith('.'.$click, $context['fbc']);
                $this->assertArrayNotHasKey('ttclid', $context);
                $this->assertArrayNotHasKey('ttp', $context);
            } else {
                $this->assertSame($click, $context['ttclid']);
                $this->assertArrayNotHasKey('fbc', $context);
                $this->assertArrayNotHasKey('fbp', $context);
            }
            $event = $this->event($this->checkoutSource($before));
            $this->app->instance(BillingAnalyticsReader::class, new class($event) implements BillingAnalyticsReader
            {
                public function __construct(private $e) {}

                public function payments(array $ids): array
                {
                    $e = $this->e;

                    return [$e->payment_id => ['payment_id' => $e->payment_id, 'viewer_id' => (string) Str::uuid(), 'order_id' => $e->order_id, 'subscription_id' => $e->subscription_id, 'transaction_kind' => $e->transaction_kind->value, 'amount_minor' => $e->amount_minor, 'refunded_amount_minor' => 0, 'currency' => 'USD', 'is_test' => true, 'paid_at' => $e->occurred_at, 'attribution_snapshot' => $e->attribution_snapshot->toArray()]];
                }
            });
            app(PaymentReceiver::class)->handle($event);
            app(PaymentReceiver::class)->handle($event);
            $delivery = DB::table('acquisition_deliveries')->where('receipt_id', $event->event_id)->sole();
            $this->httpCredentials($delivery);
            Http::fake([$platform === 'meta' ? 'graph.facebook.com/*' : 'business-api.tiktok.com/*' => Http::response($platform === 'meta' ? ['events_received' => 1] : ['code' => 0])]);
            $this->assertTrue(app(DeliveryWorker::class)->deliver($delivery->id));
            Http::assertSent(function ($request) use ($platform, $click) {
                $data = $request->data()['data'][0];
                $u = (array) $data[$platform === 'meta' ? 'user_data' : 'user'];

                return $platform === 'meta' ? str_ends_with($u['fbc'], '.'.$click) && ! isset($u['ttclid']) : $u['ttclid'] === $click && ! isset($u['fbc']);
            });
            $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'api_accepted', 'attempts' => 1]);
            $this->assertFalse(app(DeliveryWorker::class)->deliver($delivery->id));
            config(['acquisition.transport' => 'disabled', 'acquisition.external_enabled' => false]);
        }
        $this->assertDatabaseCount('acquisition_behaviors', 0); // advertising-only never becomes analytics consent
    }

    public function test_unknown_denied_malformed_and_oversized_matching_is_not_persisted(): void
    {
        [$pixel,$link] = $this->assets();
        $this->get($link['url'].'?fbclid=LOCAL_CLICK&redirect=https://attacker.invalid')->assertRedirect('/dramas/'.$link['drama_id'].'?fbclid=LOCAL_CLICK');
        $this->postJson('/api/v1/tracking/landing', $this->landing($link, ['fbclid' => 'LOCAL_CLICK']))->assertJsonPath('confirmed', false);
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => false, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/tracking/landing', $this->landing($link, ['fbclid' => 'LOCAL_CLICK']))->assertJsonPath('confirmed', false);
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        foreach ([['unknown' => 'abc'], ['fbclid' => str_repeat('a', 513)], ['fbc' => 'https://evil.invalid'], ['fbp' => 'test@example.test'], ['ttclid' => ['array']], ['ttp' => '<script>']] as $bad) {
            $this->postJson('/api/v1/tracking/landing', $this->landing($link, $bad))->assertUnprocessable();
        }
        $this->postJson('/api/v1/tracking/landing', $this->landing($link, ['fbclid' => str_repeat('a', 4200)]))->assertStatus(413);
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->postJson('/api/v1/tracking/landing', $this->landing($link))->assertJsonPath('confirmed', true);
        $context = json_decode(Crypt::decryptString(DB::table('acquisition_sources')->sole()->context_encrypted), true);
        $this->assertArrayNotHasKey('fbc', $context); // no fbclid: never invent click matching
        $this->assertDatabaseHas('acquisition_capture_diagnostics', ['reason' => 'click_match_missing', 'count' => 1]);
        Http::assertNothingSent();
    }

    public function test_late_landing_confirmation_retries_entry_events_then_attributes_without_counting_twice(): void
    {
        [$pixel,$link] = $this->assets();
        $this->get($link['url'].'?fbclid=LOCAL_DELAYED_CLICK');
        $this->postJson('/api/v1/tracking/consent', ['analytics' => true, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        $page = (string) Str::uuid();
        $events = ['events' => [['event_id' => (string) Str::uuid(), 'event_name' => 'page_view', 'properties' => ['page_instance_id' => $page, 'path' => '/dramas/'.$link['drama_id'], 'drama_id' => $link['drama_id']]]]];
        $this->postJson('/api/v1/tracking/events', $events)->assertStatus(503);
        $this->travel(2)->seconds();
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->postJson('/api/v1/tracking/landing', array_replace($this->landing($link, ['fbclid' => 'LOCAL_DELAYED_CLICK']), ['page_instance_id' => $page]))->assertJsonPath('confirmed', true);
        $this->postJson('/api/v1/tracking/events', $events)->assertJsonPath('accepted', 1);
        $this->postJson('/api/v1/tracking/events', $events)->assertJsonPath('accepted', 1);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->assertDatabaseCount('acquisition_behaviors', 1);
        $this->assertDatabaseHas('acquisition_behaviors', ['link_id' => $link['id'], 'channel' => 'meta']);
    }

    public function test_dotted_tiktok_boundary_retains_case_and_rejects_urls_scripts_whitespace_and_overlong_values(): void
    {
        [$pixel, $link] = $this->assets('tiktok');
        $badValues = ['https://attacker.invalid/click', '<script>', 'E.C.P. WITH_SPACE', "E.C.P.TRAILING\n", "E.C.P.\tTAB", str_repeat('A', 513)];
        foreach ($badValues as $bad) {
            $this->get($link['url'].'?'.http_build_query(['ttclid' => $bad]))->assertRedirect('/dramas/'.$link['drama_id']);
        }
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        foreach ($badValues as $bad) {
            $this->postJson('/api/v1/tracking/landing', $this->landing($link, ['ttclid' => $bad]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->post('/api/v1/tracking/landing', $this->landing($link, ['ttclid' => 'E.C.P.SYNTHETIC_06-CLICK']))->assertUnprocessable();
        $boundary = 'E.C.P.'.str_repeat('aB_7-', 101).'Z';
        $this->assertSame(512, strlen($boundary));
        $this->get($link['url'].'?'.http_build_query(['ttclid' => $boundary]))->assertRedirect('/dramas/'.$link['drama_id'].'?ttclid='.$boundary);
        // Start a separate explicitly consented journey for the valid boundary value.
        $this->postJson('/api/v1/tracking/consent', ['analytics' => false, 'advertising' => true, 'policy_version' => 'analytics-v1'])->assertOk();
        $this->postJson('/api/v1/tracking/landing', $this->landing($link, ['ttclid' => $boundary]))->assertJsonPath('confirmed', true);
        $source = DB::table('acquisition_sources')->sole();
        $context = json_decode(Crypt::decryptString($source->context_encrypted), true);
        $this->assertSame($boundary, $context['ttclid']);
        $this->assertStringNotContainsString($boundary, $source->context_encrypted);
        $this->assertArrayNotHasKey('fbc', $context);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        Http::assertNothingSent();
    }
}
