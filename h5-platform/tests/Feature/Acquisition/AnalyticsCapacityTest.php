<?php

namespace Tests\Feature\Acquisition;

use App\Contracts\BillingAnalyticsReader;
use App\Enums\Role;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Analytics\TrackingContext;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

final class AnalyticsCapacityTest extends AcquisitionTestCase
{
    public array $facts = [];

    public array $readerBatches = [];

    private array $target;

    private array $other;

    private string $episode;

    private $day;

    protected function setUp(): void
    {
        parent::setUp();
        config(['acquisition.analytics_enabled' => true]);
        $this->signIn($this->staff(Role::Owner));
        [, $this->target] = $this->assets();
        [, $this->other] = $this->assets();
        $this->episode = (string) Str::uuid();
        $this->day = now()->subDay()->startOfDay();
        $owner = $this;
        $this->app->instance(BillingAnalyticsReader::class, new class($owner) implements BillingAnalyticsReader
        {
            public function __construct(private AnalyticsCapacityTest $owner) {}

            public function payments(array $ids): array
            {
                $this->owner->readerBatches[] = count($ids);

                return array_intersect_key($this->owner->facts, array_flip($ids));
            }
        });
    }

    private function subject(): object
    {
        $this->travelTo($this->day->subHour());
        app(TrackingContext::class)->clear();
        app(TrackingContext::class)->consent(true, true);

        return app(TrackingContext::class)->subject();
    }

    private function row(object $subject, array $link, string $name, $time, array $p = [], array $extra = []): array
    {
        $id = (string) Str::uuid();

        return $extra + ['id' => $id, 'dedup_key' => hash('sha256', $id), 'fingerprint' => hash('sha256', $id), 'event_name' => $name, 'origin' => 'browser',
            'subject_id' => $subject->id, 'privacy_id' => $subject->privacy_id, 'session_key' => $subject->session_key, 'viewer_id' => null,
            'source_id' => null, 'link_id' => $link['id'], 'drama_id' => $link['drama_id'], 'episode_id' => $this->episode, 'drama_title' => 'Verified capacity drama', 'episode_title' => 'Verified episode', 'episode_number' => 1,
            'channel' => 'meta', 'device' => 'desktop', 'country' => 'US', 'locale' => 'zh_CN', 'is_test' => true, 'policy_version' => 'analytics-v1', 'properties' => json_encode($p, JSON_THROW_ON_ERROR),
            'occurred_at' => Clock::stamp($time), 'ordered_at' => Clock::stamp($time), 'received_at' => Clock::stamp($time)];
    }

    private function chain(object $subject, int $hour, bool $latePlay = false): void
    {
        $at = $this->day->addHours($hour);
        $play = (string) Str::uuid();
        $order = (string) Str::uuid();
        $payment = (string) Str::uuid();
        $rows = [];
        foreach (['page_view', 'play_started', 'paywall_view', 'registration_succeeded', 'email_verified', 'checkout_created', 'payment_confirmed'] as $i => $event) {
            $p = match ($event) {
                'play_started' => ['play_instance_id' => $play], 'checkout_created' => ['order_id' => $order], 'payment_confirmed' => ['payment_id' => $payment, 'transaction_kind' => 'first_subscription'],default => []
            };
            $rows[] = $this->row($subject, $this->target, $event, $at->addSeconds($i), $p);
        }
        $rows[] = $this->row($subject, $this->target, 'play_started', $latePlay ? $this->day->addDay() : $at->addSeconds(7), ['play_instance_id' => (string) Str::uuid()]);
        $rows[] = $this->row($subject, $this->target, 'watch_progress', $at->addMinute(), ['play_instance_id' => $play, 'watched_delta' => $latePlay ? 30 : 60]);
        $rows[] = $this->row($subject, $this->target, 'episode_completed', $latePlay ? $this->day->addDay()->addSecond() : $at->addMinutes(2), ['play_instance_id' => $play, 'watched_delta' => $latePlay ? 30 : 0]);
        $rows[] = $this->row($subject, $this->target, 'player_error', $at->addSeconds(8), ['error_code' => 'network']);
        $rows[] = $this->row($subject, $this->target, 'player_startup', $at->addSeconds(9), ['startup_ms' => $latePlay ? 1000 : 500]);
        $rows[] = $this->row($subject, $this->target, 'player_stall', $at->addSeconds(10), ['stall_ms' => $latePlay ? 200 : 100]);
        DB::table('acquisition_behaviors')->insert($rows);
        $this->facts[$payment] = ['payment_id' => $payment, 'viewer_id' => (string) Str::uuid(), 'order_id' => $order, 'subscription_id' => (string) Str::uuid(), 'transaction_kind' => 'first_subscription', 'amount_minor' => 300, 'refunded_amount_minor' => $latePlay ? 0 : 100, 'currency' => 'USD', 'is_test' => true, 'paid_at' => $at->addSeconds(6)->toIso8601String(), 'attribution_snapshot' => null];
    }

    public function test_heartbeats_cannot_displace_filtered_complete_cohorts_or_independent_period_metrics(): void
    {
        $busy = $this->subject();
        $other = $this->subject();
        $unrelated = $this->subject();
        // One consented subject browsed another authorized link before the target promotion.
        DB::table('acquisition_behaviors')->insert([
            $this->row($busy, $this->other, 'page_view', $this->day),
            $this->row($unrelated, $this->other, 'page_view', $this->day),
        ]);
        for ($start = 0; $start < 10050; $start += 500) {
            $batch = [];
            for ($i = $start; $i < min($start + 500, 10050); $i++) {
                $batch[] = $this->row($busy, $this->other, 'watch_progress', $this->day->addSeconds($i + 1), ['watched_delta' => 1]);
                $batch[] = $this->row($unrelated, $this->other, 'watch_progress', $this->day->addSeconds($i + 1), ['watched_delta' => 1]);
            }
            DB::table('acquisition_behaviors')->insert($batch);
        }
        $this->chain($busy, 3);
        $this->chain($other, 4, true);
        DB::table('acquisition_behaviors')->insert($this->row($busy, $this->target, 'watch_progress', $this->day->subMicrosecond(), ['watched_delta' => 999]));
        $this->travelTo($this->day->addDay()->addHours(12));
        $filters = ['from' => $this->day->toDateString(), 'until' => $this->day->toDateString(), 'link_id' => $this->target['id'], 'drama_id' => $this->target['drama_id'], 'episode_id' => $this->episode, 'device' => 'desktop', 'test' => 'test'];
        $report = app(AnalyticsReport::class)->read($filters);
        $this->assertFalse($report['limited']);
        $this->assertSame(2, $report['visitors']);
        $this->assertSame([2, 2, 2, 2, 2], array_column($report['funnel'], 'people'));
        $this->assertSame([2, 2, 2, 2, 2, 2, 2, 2], array_column($report['registration_funnel'], 'people'));
        $this->assertSame(100.0, $report['funnel'][4]['rate']);
        $this->assertSame(90, $report['watched_seconds']);
        $this->assertSame(2, $report['counts']['测试：page_view']['events']);
        $this->assertSame(3, $report['counts']['测试：play_started']['events']);
        $this->assertSame(2, $report['errors']['player_error:network']);
        $episode = $report['episodes'][0];
        $this->assertSame(2, $episode['play_people']);
        $this->assertSame(2, $episode['complete_people']);
        $this->assertSame(100.0, $episode['completion_rate']);
        $this->assertSame(750.0, $episode['startup_avg_ms']);
        $this->assertSame(300, $episode['stall_ms']);
        $this->assertSame('known', $report['finance']['status']);
        $this->assertSame(600, $report['finance']['gross_minor']);
        $this->assertSame(100, $report['finance']['refund_minor']);
        $this->assertSame(500, $report['finance']['net_minor']);
        $all = app(AnalyticsReport::class)->read(['from' => $this->day->toDateString(), 'until' => $this->day->toDateString()]);
        $this->assertFalse($all['limited']);
        $this->assertSame(20190, $all['watched_seconds']);
        $this->assertSame(20102, $all['counts']['测试：watch_progress']['events']);
        $mobile = app(AnalyticsReport::class)->read(array_replace($filters, ['device' => 'mobile']));
        $this->assertSame(0, $mobile['visitors']);
        $this->assertSame(0, $mobile['finance']['gross_minor']);
        $real = app(AnalyticsReport::class)->read(array_replace($filters, ['test' => 'real']));
        $this->assertSame(0, $real['visitors']);
        $this->assertSame(0, $real['finance']['gross_minor']);
        $next = app(AnalyticsReport::class)->read(['from' => $this->day->addDay()->toDateString(), 'until' => $this->day->addDay()->toDateString(), 'link_id' => $this->target['id']]);
        $this->assertSame(0, $next['visitors']);
        $this->assertSame(1, $next['counts']['测试：play_started']['events']);
        $this->assertSame(30, $next['watched_seconds']);
        $this->assertSame(0, $next['finance']['gross_minor']);
        $this->assertLessThanOrEqual(200, max($this->readerBatches));
    }

    public function test_real_entrance_limit_is_explicit_without_truncating_independent_episode_or_financial_metrics(): void
    {
        $base = $this->subject();
        for ($start = 0; $start < 10001; $start += 500) {
            $rows = [];
            for ($i = $start; $i < min($start + 500, 10001); $i++) {
                $subject = clone $base;
                if ($i > 0) {
                    $subject->id = (string) Str::uuid();
                    $subject->session_key = (string) Str::uuid();
                }
                $rows[] = $this->row($subject, $this->target, 'page_view', $this->day->addSeconds(1));
            }
            DB::table('acquisition_behaviors')->insert($rows);
        }
        $play = (string) Str::uuid();
        DB::table('acquisition_behaviors')->insert([
            $this->row($base, $this->target, 'play_started', $this->day->addHour(), ['play_instance_id' => $play]),
            $this->row($base, $this->target, 'episode_completed', $this->day->addHour()->addMinute(), ['play_instance_id' => $play, 'watched_delta' => 60]),
        ]);
        $this->travelTo($this->day->addDay()->addHours(12));
        $report = app(AnalyticsReport::class)->read(['from' => $this->day->toDateString(), 'until' => $this->day->toDateString(), 'link_id' => $this->target['id']]);
        $this->assertTrue($report['limited']);
        $this->assertSame(10001, $report['funnel_entry_people']);
        $this->assertSame(10000, $report['funnel_processed_people']);
        $this->assertSame(10001, $report['visitors']);
        $this->assertSame(10001, $report['observing_people']);
        $this->assertSame(10001, $report['counts']['测试：page_view']['events']);
        $this->assertNull($report['funnel'][1]['rate']);
        $this->assertSame(60, $report['watched_seconds']);
        $this->assertSame(100.0, $report['episodes'][0]['completion_rate']);
        $this->assertSame('known', $report['finance']['status']);
        $this->assertSame(0, $report['finance']['net_minor']);
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'analytics')
            ->assertSee('入口主体共10001人')->assertSee('独立计数、观看、分集和财务仍为完整筛选结果');
    }

    public function test_finance_keyset_batches_are_at_most_two_hundred_and_do_not_double_count_payment_ids(): void
    {
        $subject = $this->subject();
        $rows = [];
        $last = null;
        for ($i = 0; $i < 401; $i++) {
            $payment = (string) Str::uuid();
            $last = $payment;
            $rows[] = $this->row($subject, $this->target, 'payment_confirmed', $this->day->addHour(), ['payment_id' => $payment, 'transaction_kind' => 'first_subscription']);
            $this->facts[$payment] = ['payment_id' => $payment, 'viewer_id' => (string) Str::uuid(), 'order_id' => (string) Str::uuid(), 'subscription_id' => (string) Str::uuid(),
                'transaction_kind' => 'first_subscription', 'amount_minor' => 300, 'refunded_amount_minor' => 100, 'currency' => 'USD', 'is_test' => true, 'paid_at' => $this->day->addHour()->toIso8601String(), 'attribution_snapshot' => null];
        }
        $rows[] = $this->row($subject, $this->target, 'payment_confirmed', $this->day->addHour(), ['payment_id' => $last, 'transaction_kind' => 'first_subscription']);
        DB::table('acquisition_behaviors')->insert($rows);
        $this->travelTo($this->day->addDay()->addHours(12));
        $report = app(AnalyticsReport::class)->read(['from' => $this->day->toDateString(), 'until' => $this->day->toDateString()]);
        $this->assertSame('known', $report['finance']['status']);
        $this->assertSame(120300, $report['finance']['gross_minor']);
        $this->assertSame(40100, $report['finance']['refund_minor']);
        $this->assertSame(80200, $report['finance']['net_minor']);
        $this->assertSame(401, $report['finance']['kinds']['test:first_subscription']['payments']);
        $this->assertSame([200, 200, 1], $this->readerBatches);
    }
}
