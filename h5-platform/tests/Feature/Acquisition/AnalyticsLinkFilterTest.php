<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Analytics\LinkFilter;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Services\AssetManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

final class AnalyticsLinkFilterTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['acquisition.analytics_enabled' => true]);
    }

    private function seedLinks(string $owner, array $pixel, int $count = 61): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'owner_id' => $owner,
                'name' => $i === $count - 1 ? 'Campaign ZZZ old' : sprintf('Campaign %03d', $i),
                'platform' => 'meta', 'pixel_id' => $pixel['id'], 'drama_id' => array_key_first($this->dramas), 'drama_title' => 'Synthetic fixture',
                'code' => 'SEARCH'.bin2hex(random_bytes(12)), 'active' => true, 'version' => 1,
                'created_at' => $i === $count - 1 ? now()->subDays(2) : now(), 'updated_at' => now()];
        }
        DB::table('acquisition_links')->insert($rows);

        return $rows;
    }

    public function test_search_finds_old_link_beyond_main_page_and_has_its_own_bounded_pagination(): void
    {
        $owner = $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $rows = $this->seedLinks($owner->id, $pixel);
        $target = $rows[60];
        $this->assertNotContains($target['id'], array_column(app(AssetManager::class)->assetPage('link', 1)['items'], 'id'));
        $filter = app(LinkFilter::class);
        $first = $filter->page('Campaign');
        $this->assertCount(50, $first['items']);
        $this->assertSame(61, $first['total']);
        $this->assertSame(2, $first['last_page']);
        $this->assertContains($target['id'], array_column($filter->page('Campaign', 2)['items'], 'id'));
        $this->assertSame([$target['id']], array_column($filter->page('ZZZ old')['items'], 'id'));
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'analytics')->assertSet('linkPage', 1)
            ->set('analyticsLinkSearch', 'Campaign')->call('searchAnalyticsLinks')->assertSee('下一页链接选项')
            ->call('paginateAnalyticsLinks', 2)->assertSet('linkPage', 1)->assertSeeHtml('value="'.$target['id'].'"')
            ->set('analyticsLinkSearch', 'ZZZ old')->call('searchAnalyticsLinks')->assertSet('analyticsLinkPage', 1)
            ->assertSeeHtml('value="'.$target['id'].'"')->assertSee('共1条');
    }

    public function test_selected_authorized_link_survives_other_search_and_page_while_draft_errors_keep_selection(): void
    {
        $owner = $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $target = $this->seedLinks($owner->id, $pixel)[60];
        $filter = app(LinkFilter::class);
        $page = $filter->page('Campaign', 1, $target['id']);
        $this->assertCount(51, $page['items']);
        $this->assertContains($target['id'], array_column($page['items'], 'id'));
        $empty = $filter->page('No matching name', 1, $target['id']);
        $this->assertSame(0, $empty['total']);
        $this->assertSame($target['id'], $empty['items'][0]['id']);
        $this->assertFalse($empty['selected_unavailable']);
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'analytics')->set('analyticsFilters.link_id', $target['id'])
            ->set('analyticsLinkSearch', 'No matching name')->call('searchAnalyticsLinks')
            ->assertSet('analyticsFilters.link_id', $target['id'])->assertSeeHtml('value="'.$target['id'].'"')
            ->set('analyticsLinkSearch', str_repeat('x', 101))->call('searchAnalyticsLinks')->assertHasErrors(['analyticsLinkSearch' => 'max'])
            ->assertSet('analyticsLinkQuery', 'No matching name')->assertSet('analyticsFilters.link_id', $target['id']);
    }

    public function test_own_and_assigned_search_scope_never_leaks_same_keyword_and_revoked_selection_is_rejected(): void
    {
        $owner = $this->signIn($this->staff(Role::Owner));
        [$pixel, $assigned] = $this->assets();
        $operator = $this->staff();
        $foreign = $this->seedLinks($owner->id, $pixel, 2)[0];
        $own = $this->seedLinks($operator->id, $pixel)[60];
        app(AssetManager::class)->share('link', $assigned['id'], 1, [$operator->id]);
        $this->signIn($operator);
        $result = app(LinkFilter::class)->page('Campaign');
        $this->assertSame(61, $result['total']);
        $this->assertNotContains($foreign['id'], array_column($result['items'], 'id'));
        $this->assertSame([$own['id']], array_column(app(LinkFilter::class)->page('ZZZ old')['items'], 'id'));
        $this->assertSame([$assigned['id']], array_column(app(LinkFilter::class)->page($assigned['name'])['items'], 'id'));
        $this->signIn($owner);
        app(AssetManager::class)->share('link', $assigned['id'], 2, []);
        $this->signIn($operator);
        $revoked = app(LinkFilter::class)->page('', 1, $assigned['id']);
        $this->assertTrue($revoked['selected_unavailable']);
        $this->assertNotContains($assigned['name'], array_column($revoked['items'], 'name'));
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'analytics')->set('analyticsFilters.link_id', $assigned['id'])
            ->call('refreshAnalytics')->assertSet('analyticsFilters.link_id', $assigned['id'])->assertSee('已选链接不存在或当前无权查看')
            ->assertDontSee('访问人数 ');
        $emptyStaff = $this->signIn();
        $this->assertSame(0, app(LinkFilter::class)->page('Campaign')['total']);
        $this->assertSame([], app(LinkFilter::class)->page('Campaign')['items']);
    }

    public function test_search_treats_sql_wildcards_as_literal_name_characters(): void
    {
        $owner = $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        $rows = $this->seedLinks($owner->id, $pixel, 2);
        DB::table('acquisition_links')->where('id', $rows[0]['id'])->update(['name' => 'Promo 10%_! exact']);
        $this->assertSame([$rows[0]['id']], array_column(app(LinkFilter::class)->page('10%_!')['items'], 'id'));
        $this->assertSame(0, app(LinkFilter::class)->page('No match')['total']);
    }
}
