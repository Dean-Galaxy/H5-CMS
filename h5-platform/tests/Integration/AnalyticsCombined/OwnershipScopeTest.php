<?php

namespace Tests\Integration\AnalyticsCombined;

use App\Enums\Role;
use App\Modules\Acquisition\Analytics\AnalyticsReport;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Modules\Acquisition\Services\AssetManager;
use App\Modules\Billing\Services\OutboxService;
use Illuminate\Support\Facades\Http;
use Tests\Integration\AnalyticsCombined\Support\CombinedTestCase;
use Tests\Integration\AnalyticsCombined\Support\ControlledPayment;

/** Original authorization: owned OR assigned. Pending 05's final compatibility patch, not yet a passed case. */
final class OwnershipScopeTest extends CombinedTestCase
{
    public function test_owned_or_assigned_finance_dimensions_and_diagnostics_revoke_immediately_without_empty_scope_fallback(): void
    {
        $assignedDrama = $this->drama; $assigned = $this->link;
        $this->asStaff($this->owner);
        $ownedDrama = $this->fixture(6, 6, true);
        $otherDrama = $this->fixture(6, 6, true);
        $manager = app(AssetManager::class);
        $other = $manager->saveLink(null, ['name' => '06 other operator scope', 'platform' => 'meta', 'drama_id' => $otherDrama->id,
            'pixel_id' => $assigned['pixel_id'], 'active' => true, 'version' => 0]);
        $operator = $this->makeStaff(Role::Acquisition);
        $this->asStaff($operator);
        $pixel = $manager->savePixel(null, ['name' => '06 operator own TEST', 'platform' => 'meta', 'external_id' => '1060000002',
            'environment' => 'test', 'active' => true, 'version' => 0]);
        $owned = $manager->saveLink(null, ['name' => '06 operator own scope', 'platform' => 'meta', 'drama_id' => $ownedDrama->id,
            'pixel_id' => $pixel['id'], 'active' => true, 'version' => 0]);
        $this->asStaff($this->owner);
        foreach ([$assigned, $owned] as $link) {
            $row = app(AssetAccess::class)->get('link', $link['id']);
            $manager->share('link', $link['id'], $row->version, [$operator->id]);
        }
        foreach ([[$ownedDrama, $owned], [$assignedDrama, $assigned], [$otherDrama, $other]] as [$drama, $link]) {
            $this->drama = $drama; $this->link = $link;
            $this->anonymous(); $this->consent(); $this->landing(); $this->registerAndVerify();
            $order = $this->checkout(); ControlledPayment::settle($order->id, $this->gateway);
            app(OutboxService::class)->dispatchPending();
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
        $this->asStaff($operator);
        $report = app(AnalyticsReport::class)->read(['test' => 'test']);
        $this->assertEqualsCanonicalizing([$owned['id'], $assigned['id']], app(AssetAccess::class)->query('link')->pluck('id')->all());
        $this->assertSame(600, $report['finance']['gross_minor']);
        $this->assertEqualsCanonicalizing([$ownedDrama->id, $assignedDrama->id], array_keys($report['dimensions']['dramas']));
        $this->assertSame(2, array_sum(array_map(fn ($r) => (int) $r->count, $report['diagnostics']['deliveries'])));
        $this->assertSame(2, array_sum(array_map(fn ($r) => (int) $r->count, $report['diagnostics']['source_status'])));
        $this->assertStringNotContainsString($otherDrama->id, json_encode($report));

        $this->asStaff($this->owner);
        foreach ([$assigned, $owned] as $link) {
            $row = app(AssetAccess::class)->get('link', $link['id']);
            $manager->share('link', $link['id'], $row->version, []);
        }
        $this->asStaff($operator);
        $revoked = app(AnalyticsReport::class)->read(['test' => 'test']);
        $this->assertSame([$owned['id']], app(AssetAccess::class)->query('link')->pluck('id')->all());
        $this->assertSame(300, $revoked['finance']['gross_minor']);
        $this->assertSame([$ownedDrama->id], array_keys($revoked['dimensions']['dramas']));
        $this->assertSame(1, array_sum(array_map(fn ($r) => (int) $r->count, $revoked['diagnostics']['deliveries'])));
        $this->assertStringNotContainsString($assignedDrama->id, json_encode($revoked));
        $this->assertStringNotContainsString($otherDrama->id, json_encode($revoked));

        $this->asStaff($this->makeStaff(Role::Acquisition));
        $empty = app(AnalyticsReport::class)->read(['test' => 'test']);
        $this->assertSame([], app(AssetAccess::class)->query('link')->pluck('id')->all());
        $this->assertSame(0, $empty['finance']['gross_minor']);
        $this->assertSame([], $empty['dimensions']['dramas']);
        $this->assertSame([], $empty['diagnostics']['deliveries']);
        $this->assertSame([], $empty['diagnostics']['source_status']);
        Http::assertNothingSent();
    }
}
