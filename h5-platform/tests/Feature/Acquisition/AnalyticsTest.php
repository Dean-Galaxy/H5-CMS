<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\BehaviorEventRecorder;
use App\Contracts\BillingAnalyticsReader;
use App\Contracts\CatalogReader;
use App\Data\Drama;
use App\Data\Episode;
use App\Enums\BehaviorRecordingResult as Result;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Acquisition\Services\AttributionRecorder;
use App\Modules\Acquisition\Services\DeliveryWorker;
use App\Modules\Acquisition\Services\PaymentReceiver;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;

final class AnalyticsTest extends AcquisitionTestCase
{
    private array $link;

    private array $pixel;

    private string $episode;

    public array $financial = [];

    private array $episodeMap = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['acquisition.analytics_enabled' => true]);
        $this->signIn($this->staff(Role::Owner));
        [$this->pixel, $this->link] = $this->assets();
        $this->episode = (string) Str::uuid();
        $this->episodeMap[array_key_first($this->dramas)] = [new Episode($this->episode, 1, 'Episode', 60, 'free', 'ready')];
        $catalog = Mockery::mock(CatalogReader::class);
        $catalog->shouldReceive('drama')->andReturnUsing(fn ($id) => isset($this->dramas[$id])
            ? new Drama($id, $this->dramas[$id]->title, '', null, count($this->episodeMap[$id] ?? []), count($this->episodeMap[$id] ?? []), 5, 'published', $this->episodeMap[$id] ?? [])
            : throw new ApiProblem('NOT_FOUND', 404, 'Missing'));
        $this->app->instance(CatalogReader::class, $catalog);
        $owner = $this;
        $this->app->instance(BillingAnalyticsReader::class, new class($owner) implements BillingAnalyticsReader
        {
            public function __construct(private AnalyticsTest $owner) {}

            public function payments(array $paymentIds): array
            {
                return array_intersect_key($this->owner->financial, array_flip($paymentIds));
            }
        });
        request()->headers->set('User-Agent', 'Mozilla/5.0 local explicit analytics test');
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function consent(bool $analytics = true, bool $ads = true): void
    {
        app(TrackingContext::class)->consent($analytics, $ads);
    }

    private function prepare(): void
    {
        app(AttributionRecorder::class)->prepare(basename($this->link['url']));
    }

    private function eventBrowser(string $event = 'page_view', array $p = [], ?string $id = null): Result
    {
        return app(BehaviorRecorder::class)->browser($id ?? $this->uuid(), $event, $p + ['page_instance_id' => $this->uuid(), 'path' => '/']);
    }

    private function seedLanding(): void
    {
        $this->consent();
        $this->prepare();
        $this->assertTrue(app(AttributionRecorder::class)->confirm($this->link['drama_id']));
        $this->assertSame(Result::Accepted, $this->eventBrowser('landing_view', ['drama_id' => $this->link['drama_id'], 'path' => '/dramas/'.$this->link['drama_id']]));
    }

    private function viewer(): string
    {
        $v = Viewer::create(['name' => 'Analytics fixture', 'email' => $this->uuid().'@example.test', 'password' => 'LocalPassword1234']);
        $this->actingAs($v, 'web');

        return $v->id;
    }

    public function test_click_requires_visible_confirmation_consent_and_exact_window_without_refresh_extension(): void
    {
        $a = app(AttributionRecorder::class);
        $this->prepare();
        $this->assertNull($a->snapshot(null));
        $this->assertFalse($a->confirm($this->link['drama_id']));
        $this->assertDatabaseCount('acquisition_subjects', 0);
        $this->assertDatabaseCount('acquisition_sources', 0);
        $this->seedLanding();
        $before = $a->snapshot(null);
        $this->assertNotNull($before);
        $this->assertNull($a->snapshot($before->source_id));
        $this->travel(1)->days();
        $this->eventBrowser();
        $this->assertSame($before->captured_at, $a->snapshot(null)->captured_at);
        $this->travel(6)->days();
        $this->assertNull($a->snapshot(null));
        Http::assertNothingSent();
    }

    public function test_advertising_only_landing_does_not_create_analysis_subject_and_withdrawal_never_revives_source(): void
    {
        $this->consent(false, true);
        $this->prepare();
        $this->assertTrue(app(AttributionRecorder::class)->confirm($this->link['drama_id']));
        $snapshot = app(AttributionRecorder::class)->snapshot(null);
        $this->assertNotNull($snapshot);
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseCount('acquisition_subjects', 0);
        $this->consent(false, false);
        $this->assertNotNull(DB::table('acquisition_sources')->value('revoked_at'));
        $this->consent(false, true);
        $this->assertNull(app(AttributionRecorder::class)->snapshot(null));
        $this->assertFalse(app(AttributionRecorder::class)->confirm($this->link['drama_id']));
    }

    public function test_valid_new_click_overrides_but_invalid_prefetch_disabled_and_normal_visits_do_not(): void
    {
        $this->seedLanding();
        $before = app(AttributionRecorder::class)->snapshot(null);
        request()->headers->set('Sec-Purpose', 'prefetch');
        $this->prepare();
        $this->assertFalse(app(AttributionRecorder::class)->confirm($this->link['drama_id']));
        $this->assertSame($before->source_id, app(AttributionRecorder::class)->snapshot(null)->source_id);
        request()->headers->remove('Sec-Purpose');
        $this->travel(10)->minutes();
        $this->prepare();
        app(AttributionRecorder::class)->confirm($this->link['drama_id']);
        $this->assertNotSame($before->source_id, app(AttributionRecorder::class)->snapshot(null)->source_id);
        DB::table('acquisition_links')->where('id', $this->link['id'])->update(['active' => false]);
        try {
            $this->prepare();
            $this->fail('inactive accepted');
        } catch (ApiProblem $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function test_separate_analytics_consent_client_idempotency_forged_fields_and_play_session_counts(): void
    {
        $this->consent(true, false);
        $id = $this->uuid();
        $page = $this->uuid();
        $props = ['page_instance_id' => $page, 'path' => '/'];
        $this->assertSame(Result::Accepted, $this->eventBrowser('page_view', $props, $id));
        $this->assertSame(Result::Accepted, $this->eventBrowser('page_view', $props, $id));
        $this->assertSame(Result::Skipped, $this->eventBrowser('page_view', $props + ['viewer_id' => $this->uuid()], $id));
        $this->assertSame(Result::Skipped, $this->eventBrowser('payment_confirmed', $props));
        $this->assertSame(Result::Skipped, $this->eventBrowser('page_view', ['path' => '/?token=private']));
        $this->assertSame(Result::Skipped, $this->eventBrowser('page_view', ['path' => '/catalog', 'page_instance_id' => $page], $id));
        $play = ['play_instance_id' => $this->uuid(), 'drama_id' => $this->link['drama_id'], 'episode_id' => $this->episode];
        $this->assertSame(Result::Accepted, $this->eventBrowser('play_started', $play));
        $this->assertSame(Result::Accepted, $this->eventBrowser('play_started', $play));
        $this->travel(20)->seconds();
        $this->eventBrowser('watch_progress', $play + ['watched_seconds' => 10, 'position_seconds' => 59]);
        $this->eventBrowser('watch_progress', $play + ['watched_seconds' => 10, 'position_seconds' => 1]);
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'play_started')->count());
        $this->assertSame(10, DB::table('acquisition_behaviors')->where('event_name', 'watch_progress')->get()->sum(fn ($r) => json_decode($r->properties, true)['watched_delta']));
        $this->assertSame('unknown', DB::table('acquisition_behaviors')->value('channel'));
        $this->consent(false, false);
        $this->assertSame(Result::Skipped, $this->eventBrowser());
    }

    public function test_anonymous_login_chain_is_preserved_but_account_switch_and_logout_rotate(): void
    {
        $this->seedLanding();
        $subject = DB::table('acquisition_subjects')->first()->id;
        $viewer = $this->viewer();
        $this->assertTrue(app(BehaviorEventRecorder::class)->record('login_succeeded', ['viewer_id' => $viewer], 'login:'.$viewer));
        $this->assertSame($subject, app(TrackingContext::class)->subject()->id);
        $this->viewer();
        app(TrackingContext::class)->publicContext();
        $this->assertNull(app(AttributionRecorder::class)->snapshot(null));
        $this->assertSame('unknown', app(TrackingContext::class)->publicContext()['consent']['analytics']);
        $this->consent();
        $this->eventBrowser();
        $this->assertNotSame($subject, app(TrackingContext::class)->subject()->id);
        app(BehaviorEventRecorder::class)->clearBrowserContext();
        $this->assertNull(app(TrackingContext::class)->subject());
    }

    public function test_reliable_payment_dedup_refund_corrections_and_current_finance_without_cross_billing_tables(): void
    {
        $this->seedLanding();
        $viewer = $this->viewer();
        app(TrackingContext::class)->subject();
        $source = app(AttributionRecorder::class)->snapshot(null);
        $event = $this->event($source);
        $this->financial[$event->payment_id] = ['payment_id' => $event->payment_id, 'viewer_id' => $viewer, 'order_id' => $event->order_id,
            'subscription_id' => $event->subscription_id, 'transaction_kind' => $event->transaction_kind->value, 'amount_minor' => 300,
            'refunded_amount_minor' => 0, 'currency' => 'USD', 'is_test' => true, 'paid_at' => now()->toIso8601String(), 'attribution_snapshot' => $source->toArray()];
        app(PaymentReceiver::class)->handle($event);
        app(PaymentReceiver::class)->handle($event);
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
        $r = app(BehaviorEventRecorder::class);
        foreach ([[1, 100], [2, 300], [3, 0]] as [$version,$amount]) {
            $this->financial[$event->payment_id]['refunded_amount_minor'] = $amount;
            $this->assertSame(Result::Accepted, $r->recordFact('refund_confirmed', ['payment_id' => $event->payment_id, 'refunded_amount_minor' => $amount,
                'fact_version' => $version, 'occurred_at' => now()->toIso8601String()], 'refund:'.$version));
        }
        $report = app(AnalyticsReport::class)->read();
        $this->assertSame(300, $report['finance']['gross_minor']);
        $this->assertSame(0, $report['finance']['refund_minor']);
        $this->assertSame(300, $report['finance']['net_minor']);
        $this->consent(false, false);
        $this->assertSame(Result::Skipped, $r->recordFact('refund_confirmed', ['payment_id' => $event->payment_id, 'fact_version' => 4, 'refunded_amount_minor' => 100], 'refund:4'));
        Http::assertNothingSent();
    }

    public function test_savepoint_failure_is_retry_without_poisoning_outer_transaction_and_late_consent_skips(): void
    {
        $this->consent();
        $r = app(BehaviorEventRecorder::class);
        $this->assertSame(Result::Skipped, $r->recordFact('login_failed', ['occurred_at' => now()->subDay()->toIso8601String()], 'past'));
        DB::beginTransaction();
        DB::statement('ALTER TABLE acquisition_behaviors RENAME TO acquisition_behaviors_unavailable');
        $this->assertSame(Result::Retry, $r->recordFact('login_failed', ['error_code' => 'INVALID_CREDENTIALS'], 'failure'));
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS n')->n);
        DB::statement('ALTER TABLE acquisition_behaviors_unavailable RENAME TO acquisition_behaviors');
        DB::commit();
        $this->assertSame(Result::Accepted, $r->recordFact('login_failed', ['error_code' => 'INVALID_CREDENTIALS'], 'failure'));
    }

    public function test_rebinding_produces_new_code_preserves_history_and_prune_leaves_sources_and_financial_facts(): void
    {
        $this->seedLanding();
        $before = app(AttributionRecorder::class)->snapshot(null);
        $pixel = app(AssetManager::class)->savePixel(null, $this->pixelInput());
        $new = app(AssetManager::class)->saveLink($this->link['id'], ['name' => 'Rebound', 'platform' => 'meta', 'drama_id' => $this->link['drama_id'], 'pixel_id' => $pixel['id'], 'active' => true, 'version' => 1]);
        $this->assertNotSame($new['url'], $this->link['url']);
        $this->assertDatabaseHas('acquisition_links', ['id' => $this->link['id'], 'active' => false]);
        $this->assertSame($before->source_id, app(AttributionRecorder::class)->snapshot(null)->source_id);
        DB::table('acquisition_behaviors')->update(['received_at' => now()->subDays(91)]);
        $this->artisan('acquisition:analytics-prune')->assertSuccessful();
        $this->assertDatabaseCount('acquisition_behaviors', 0);
        $this->assertDatabaseCount('acquisition_sources', 1);
    }

    public function test_report_authorized_scope_zero_denominator_and_out_of_order_funnel_are_explicit(): void
    {
        $this->consent();
        $this->eventBrowser('checkout_click');
        $this->travel(1)->seconds();
        $this->eventBrowser();
        $report = app(AnalyticsReport::class)->read();
        $this->assertSame(1, $report['funnel'][0]['people']);
        $this->assertSame(0, $report['funnel'][1]['people']);
        $this->assertNull($report['funnel'][2]['rate']);
        $operator = $this->staff();
        $this->signIn($operator);
        $report = app(AnalyticsReport::class)->read();
        $this->assertSame(0, $report['visitors']);
        $this->assertSame([], $report['diagnostics']['capture_failures']);
        $this->expectException(ApiProblem::class);
        app(AnalyticsReport::class)->read(['link_id' => $this->link['id']]);
    }

    public function test_same_second_provider_payment_uses_exact_order_causality_even_when_outbox_arrives_after_play(): void
    {
        $this->seedLanding();
        $viewer = $this->viewer();
        app(TrackingContext::class)->subject();
        $this->travelTo(now()->startOfSecond()->addMilliseconds(100));
        $this->eventBrowser('play_started', ['play_instance_id' => $this->uuid(), 'drama_id' => $this->link['drama_id'], 'episode_id' => $this->episode]);
        $this->travelTo(now()->startOfSecond()->addMilliseconds(500));
        $event = $this->event(app(AttributionRecorder::class)->snapshot(null));
        app(BehaviorEventRecorder::class)->record('checkout_created', ['order_id' => $event->order_id, 'viewer_id' => $viewer], 'checkout:'.$event->order_id);
        $paidAt = now()->startOfSecond()->toIso8601String();
        $this->travelTo(now()->startOfSecond()->addMilliseconds(800));
        $this->eventBrowser('play_started', ['play_instance_id' => $this->uuid(), 'drama_id' => $this->link['drama_id'], 'episode_id' => $this->episode]);
        $this->financial[$event->payment_id] = ['payment_id' => $event->payment_id, 'viewer_id' => $viewer, 'order_id' => $event->order_id,
            'subscription_id' => $event->subscription_id, 'transaction_kind' => 'first_subscription', 'amount_minor' => 300,
            'refunded_amount_minor' => 0, 'currency' => 'USD', 'is_test' => true, 'paid_at' => $paidAt,
            'attribution_snapshot' => $event->attribution_snapshot->toArray()];
        $this->travel(2)->minutes();
        app(PaymentReceiver::class)->handle($event);
        $row = DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->first();
        $this->assertSame($paidAt, CarbonImmutable::parse($row->occurred_at)->toIso8601String());
        $this->assertSame('500000', CarbonImmutable::parse($row->ordered_at)->format('u'));
        $report = app(AnalyticsReport::class)->read();
        $this->assertSame([1, 1, 1, 1, 1], array_column($report['funnel'], 'people'));
        // An actually old financial timestamp must not be moved forward to the new checkout.
        $other = $this->event($event->attribution_snapshot);
        app(BehaviorEventRecorder::class)->record('checkout_created', ['order_id' => $other->order_id, 'viewer_id' => $viewer], 'checkout:'.$other->order_id);
        $this->financial[$other->payment_id] = array_replace($this->financial[$event->payment_id], ['payment_id' => $other->payment_id, 'order_id' => $other->order_id, 'subscription_id' => $other->subscription_id, 'paid_at' => now()->subHour()->toIso8601String()]);
        app(PaymentReceiver::class)->handle($other);
        $this->assertSame(1, DB::table('acquisition_behaviors')->where('event_name', 'payment_confirmed')->count());
    }

    public function test_consented_cookie_restores_anonymous_source_across_server_session_expiry_without_extending_click(): void
    {
        $this->seedLanding();
        $c = app(TrackingContext::class);
        $id = $c->privacy()->id;
        $before = app(AttributionRecorder::class)->snapshot(null);
        request()->session()->forget('tracking');
        request()->cookies->set($c->cookieName(), $id); // Trusted post-EncryptCookies value, not a raw HTTP input.
        $this->travel(2)->days();
        $this->assertSame('granted', $c->publicContext()['consent']['analytics']);
        $this->assertSame($before->source_id, app(AttributionRecorder::class)->snapshot(null)->source_id);
        $this->assertSame($before->captured_at, app(AttributionRecorder::class)->snapshot(null)->captured_at);
        $c->clear();
        $this->assertNull(app(AttributionRecorder::class)->snapshot(null));
    }

    public function test_episode_cutpoints_use_verified_labels_cumulative_deltas_and_matching_play_completion(): void
    {
        $this->consent(true, false);
        $drama = $this->link['drama_id'];
        $otherEpisode = $this->uuid();
        $this->episodeMap[$drama][] = new Episode($otherEpisode, 2, 'Second episode', 60, 'free', 'ready');
        $a = ['drama_id' => $drama, 'episode_id' => $this->episode, 'play_instance_id' => $this->uuid(), 'page_instance_id' => $this->uuid()];
        $startId = $this->uuid();
        $this->eventBrowser('play_started', $a, $startId);
        $this->eventBrowser('play_started', $a, $startId);
        $startupId = $this->uuid();
        $this->eventBrowser('player_startup', $a + ['startup_ms' => 500], $startupId);
        $this->eventBrowser('player_startup', $a + ['startup_ms' => 500], $startupId);
        $stallId = $this->uuid();
        $this->eventBrowser('player_stall', $a + ['stall_ms' => 1200], $stallId);
        $this->eventBrowser('player_stall', $a + ['stall_ms' => 1200], $stallId);
        $this->travel(10)->seconds();
        $progressId = $this->uuid();
        $this->eventBrowser('watch_progress', $a + ['watched_seconds' => 4, 'position_seconds' => 58], $progressId);
        $this->eventBrowser('watch_progress', $a + ['watched_seconds' => 4, 'position_seconds' => 58], $progressId);
        $this->eventBrowser('watch_progress', $a + ['watched_seconds' => 4, 'position_seconds' => 1]);
        $this->assertSame(Result::Skipped, $this->eventBrowser('episode_completed', $a + ['watched_seconds' => 4]));
        $this->travel(50)->seconds();
        $this->eventBrowser('episode_completed', $a + ['watched_seconds' => 60]);
        $b = ['drama_id' => $drama, 'episode_id' => $otherEpisode, 'play_instance_id' => $this->uuid()];
        $this->eventBrowser('player_error', $b + ['error_code' => 'network']);
        $this->assertSame(Result::Skipped, $this->eventBrowser('episode_completed', $b));
        $report = app(AnalyticsReport::class)->read();
        $this->assertCount(2, $report['episodes']);
        $first = collect($report['episodes'])->firstWhere('episode_id', $this->episode);
        $this->assertSame(1, $first['play_people']);
        $this->assertSame(1, $first['complete_people']);
        $this->assertSame(100.0, $first['completion_rate']);
        $this->assertSame(60, $first['watched_seconds']);
        $this->assertSame(1, $first['startup_samples']);
        $this->assertSame(500.0, $first['startup_avg_ms']);
        $this->assertSame(1, $first['stall_count']);
        $this->assertSame(1200, $first['stall_ms']);
        $this->assertSame(1, $first['episode_number']);
        $second = collect($report['episodes'])->firstWhere('episode_id', $otherEpisode);
        $this->assertNull($second['completion_rate']);
        $this->assertSame(1, $second['play_errors']);
        $this->assertCount(1, app(AnalyticsReport::class)->read(['drama_id' => $drama, 'episode_id' => $this->episode])['episodes']);
        $this->assertCount(0, app(AnalyticsReport::class)->read(['drama_id' => $this->uuid()])['episodes']);
        app(BehaviorEventRecorder::class)->record('checkout_failed', ['drama_id' => $this->uuid(), 'episode_id' => $this->uuid(), 'error_code' => 'unavailable']);
        $unknown = collect(app(AnalyticsReport::class)->read()['episodes'])->firstWhere('drama_title', '未知剧目');
        $this->assertNotNull($unknown);
        $this->assertNull($unknown['completion_rate']);
    }

    public function test_analytics_tab_renders_with_scope_and_invalid_date_keeps_filter_inputs(): void
    {
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'analytics')
            ->assertSet('tab', 'analytics')->assertSee('站内分析')->assertSee('按剧集观看卡点')->assertSee('平均毫秒')
            ->set('analyticsFilters.from', '2020-01-01')->call('refreshAnalytics')->assertSee('最多90天')->assertSet('analyticsFilters.from', '2020-01-01');
    }

    public function test_explicit_advertising_withdrawal_blocks_previously_queued_delivery_and_keeps_order_snapshot(): void
    {
        $this->seedLanding();
        $viewer = $this->viewer();
        app(TrackingContext::class)->subject();
        $source = app(AttributionRecorder::class)->snapshot(null);
        $event = $this->event($source);
        $this->financial[$event->payment_id] = ['payment_id' => $event->payment_id, 'viewer_id' => $viewer, 'order_id' => $event->order_id, 'subscription_id' => $event->subscription_id, 'transaction_kind' => 'first_subscription', 'amount_minor' => 300, 'refunded_amount_minor' => 0, 'currency' => 'USD', 'is_test' => true, 'paid_at' => now()->toIso8601String(), 'attribution_snapshot' => $source->toArray()];
        app(PaymentReceiver::class)->handle($event);
        $delivery = DB::table('acquisition_deliveries')->sole();
        $this->httpCredentials($delivery);
        $snapshot = DB::table('acquisition_receipts')->sole()->attribution_snapshot;
        $this->consent(true, false);
        $this->assertTrue(app(DeliveryWorker::class)->deliver($delivery->id));
        $this->assertDatabaseHas('acquisition_deliveries', ['id' => $delivery->id, 'state' => 'blocked', 'last_error' => 'CONSENT_REQUIRED_OR_REVOKED', 'attempts' => 0]);
        $this->assertSame($snapshot, DB::table('acquisition_receipts')->sole()->attribution_snapshot);
        Http::assertNothingSent();
    }

    public function test_assigned_operator_sees_only_its_link_aggregates_and_revocation_removes_report_access(): void
    {
        $this->seedLanding();
        $operator = $this->staff();
        app(AssetManager::class)->share('link', $this->link['id'], 1, [$operator->id]);
        $this->signIn($operator);
        $report = app(AnalyticsReport::class)->read(['link_id' => $this->link['id']]);
        $this->assertSame(1, $report['visitors']);
        $this->assertSame([], $report['diagnostics']['capture_failures']);
        $this->assertStringNotContainsString('@example.test', json_encode($report));
        $this->signIn($this->staff(Role::Owner));
        app(AssetManager::class)->share('link', $this->link['id'], 2, []);
        $this->signIn($operator);
        $this->assertSame(0, app(AnalyticsReport::class)->read()['visitors']);
        $this->problem('NOT_FOUND', fn () => app(AnalyticsReport::class)->read(['link_id' => $this->link['id']]));
    }

    public function test_own_links_remain_visible_after_redundant_assignment_revocation_without_exposing_foreign_links(): void
    {
        $foreign = $this->link;
        $operator = $this->staff();
        $this->signIn($operator);
        [$pixel,$own] = $this->assets();
        $this->link = $own;
        $this->seedLanding();
        $report = app(AnalyticsReport::class)->read(['link_id' => $own['id']]);
        $this->assertSame(1, $report['visitors']);
        $this->assertSame([], $report['diagnostics']['capture_failures']);
        $this->problem('NOT_FOUND', fn () => app(AnalyticsReport::class)->read(['link_id' => $foreign['id']]));
        $this->signIn($this->staff(Role::Owner));
        app(AssetManager::class)->share('link', $own['id'], 1, [$operator->id]);
        app(AssetManager::class)->share('link', $own['id'], 2, []);
        $this->signIn($operator);
        $this->assertSame(1, app(AnalyticsReport::class)->read(['link_id' => $own['id']])['visitors']);
        $this->assertSame(1, app(AnalyticsReport::class)->read()['visitors']);
        $this->problem('NOT_FOUND', fn () => app(AnalyticsReport::class)->read(['link_id' => $foreign['id']]));
    }
}
