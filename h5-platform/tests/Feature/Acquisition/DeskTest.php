<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use App\Modules\Acquisition\Services\AcquisitionStore;
use App\Modules\Acquisition\Services\AssetManager;
use App\Support\Admin\UsdAmount;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

final class DeskTest extends AcquisitionTestCase
{
    public function test_old_pixel_beyond_two_hundred_assets_remains_paged_and_repopulates_saved_link(): void
    {
        $staff = $this->signIn();
        [$pixel, $link] = $this->assets();
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = ['id' => AcquisitionStore::id(), 'owner_id' => $staff->id, 'name' => 'Later local fixture '.$i,
                'platform' => 'meta', 'external_id' => (string) (100000 + $i), 'environment' => 'test',
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('acquisition_pixels')->insert($rows);
        $manager = app(AssetManager::class);
        $this->assertNotContains($pixel['id'], array_column($manager->pixels(), 'id'));
        $last = $manager->assetPage('pixel', 5);
        $this->assertSame(202, $last['total']);
        $this->assertContains($pixel['id'], array_column($last['items'], 'id'));
        $page = Livewire::test(AcquisitionDesk::class)->call('selectLink', $link['id'])->assertSet('link.pixel_id', $pixel['id'])
            ->assertSeeHtml('value="'.$pixel['id'].'"')->set('link.name', 'Old binding retained')->call('saveLink')->assertHasNoErrors();
        $this->assertDatabaseHas('acquisition_links', ['id' => $link['id'], 'pixel_id' => $pixel['id'], 'name' => 'Old binding retained']);
        $this->signIn();
        $this->assertSame(0, $manager->assetPage('pixel', 5)['total']);
    }

    public function test_form_save_reopen_local_probe_link_edit_disable_and_summary(): void
    {
        $this->signIn();
        $page = Livewire::test(AcquisitionDesk::class)->assertOk()->assertSee('平台确认分别记录')
            ->assertSee('尚无可见像素')->set('pixel', $this->pixelInput('tiktok'))->call('savePixel')->assertHasNoErrors();
        $pixelId = $page->get('pixelId');
        $this->assertNotNull($pixelId);
        $page = Livewire::test(AcquisitionDesk::class)->call('selectPixel', $pixelId)->assertSet('pixel.version', 1)
            ->assertSet('pixel.platform', 'tiktok')->call('simulatePixel', $pixelId)->assertSee('本站模拟：通过')
            ->call('loadCatalog')->assertSee('testing 片单样例')
            ->set('link.name', 'Test link')->set('link.platform', 'tiktok')->set('link.pixel_id', $pixelId)
            ->set('link.drama_id', array_key_first($this->dramas))->call('saveLink')->assertHasNoErrors();
        $linkId = $page->get('linkId');
        $this->assertNotNull($linkId);
        Livewire::test(AcquisitionDesk::class)->call('selectLink', $linkId)->set('link.name', 'Unsaved change')
            ->call('restoreEditor', 'link')->assertSet('link.name', 'Test link');
        $page = Livewire::test(AcquisitionDesk::class)->call('selectLink', $linkId)->assertSet('link.name', 'Test link')
            ->set('link.name', 'Renamed link')->set('link.active', false)->call('saveLink')->assertHasNoErrors()->assertSet('link.version', 2)
            ->assertSet('link.active', 0)
            ->call('showSummary', $linkId)->assertSet('tab', 'payments')
            ->assertSee('暂无对应付款事实')->call('selectTab', 'deliveries')->assertSee('暂无对应发送记录');
        Livewire::test(AcquisitionDesk::class)->call('selectLink', $linkId)->assertSet('link.active', 0);
        Livewire::test(AcquisitionDesk::class)->call('selectPixel', $pixelId)->set('pixel.active', '0')->call('savePixel');
        Livewire::test(AcquisitionDesk::class)->call('selectPixel', $pixelId)->assertSet('pixel.active', 0);
        $this->assertDatabaseHas('acquisition_links', ['id' => $linkId, 'name' => 'Renamed link', 'active' => false]);
        $this->assertDatabaseCount('acquisition_receipts', 0);
    }

    public function test_hidden_action_calls_cannot_edit_foreign_pixel_or_self_assign(): void
    {
        $creator = $this->signIn();
        [$pixel, $link] = $this->assets();
        $this->signIn();
        $page = Livewire::test(AcquisitionDesk::class)->assertDontSee($pixel['external_id'])->set('pixelId', $pixel['id'])
            ->set('pixel', array_intersect_key($pixel, $this->pixelInput()))->set('pixel.name', 'Hijacked')->call('savePixel');
        Notification::assertNotified('资产不存在或无权查看');
        $this->assertDatabaseMissing('acquisition_pixels', ['name' => 'Hijacked']);
        $page->set('shareType', 'pixel')->set('shareId', $pixel['id'])->set('staffIds', [auth('staff')->id()])->call('share');
        Notification::assertNotified('无权执行此操作');
        $this->assertDatabaseCount('acquisition_assignments', 0);
    }

    public function test_page_permissions_do_not_bootstrap_assets_or_bypass_mfa(): void
    {
        $this->get('/admin/acquisition-desk')->assertRedirect('/admin/login');
        $this->signIn($this->staff(Role::Support));
        $this->get('/admin/acquisition-desk')->assertForbidden();
        $this->signIn();
        request()->session()->forget('identity.mfa_staff');
        request()->session()->save();
        $this->get('/admin/acquisition-desk')->assertRedirect('/admin/login');
        $this->assertDatabaseCount('acquisition_pixels', 0);
    }

    public function test_workspace_tabs_are_allowlisted_and_assignment_tab_is_owner_only(): void
    {
        $this->signIn($this->staff(Role::Owner));
        Livewire::test(AcquisitionDesk::class)->assertOk()->assertSee('推广链接')->assertSee('像素资产')->assertSee('本站付款汇总')->assertSee('发送状态')
            ->assertSee('负责人分配')
            ->assertSet('tab', 'links')
            ->call('selectTab', 'pixels')->assertSet('tab', 'pixels')
            ->call('selectTab', 'deliveries')->assertSet('tab', 'deliveries')
            ->call('selectTab', 'payments')->assertSet('tab', 'payments')
            ->call('selectTab', 'assignments')->assertSet('tab', 'assignments')
            ->call('selectTab', 'unknown')->assertSet('tab', 'assignments');

        $this->signIn($this->staff(Role::Acquisition));
        $this->get('/admin/acquisition-desk')->assertOk()->assertDontSee('负责人分配');
        Livewire::test(AcquisitionDesk::class)->call('selectTab', 'assignments')->assertSet('tab', 'links');
    }

    public function test_url_tab_restores_only_authorized_context_and_invalid_input_falls_back(): void
    {
        $this->signIn($this->staff(Role::Owner));
        foreach (['pixels', 'payments', 'deliveries', 'assignments'] as $tab) {
            Livewire::withQueryParams(['tab' => $tab])->test(AcquisitionDesk::class)->assertSet('tab', $tab);
        }
        Livewire::withQueryParams(['tab' => 'unknown'])->test(AcquisitionDesk::class)->assertSet('tab', 'links');
        $this->signIn();
        Livewire::withQueryParams(['tab' => 'assignments'])->test(AcquisitionDesk::class)
            ->assertSet('tab', 'links')->assertDontSee('负责人分配')
            ->set('tab', 'assignments')->assertSet('tab', 'links')
            ->set('tab', 'unknown')->assertSet('tab', 'links');
        $this->get('/admin/acquisition-desk?tab=assignments')->assertOk()->assertDontSee('负责人分配');
    }

    public function test_assignment_opens_authorized_tab_and_failed_save_preserves_editor(): void
    {
        $this->signIn($this->staff(Role::Owner));
        [$pixel] = $this->assets();
        Livewire::test(AcquisitionDesk::class)->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSet('tab', 'assignments')->assertSet('shareId', $pixel['id']);
        $page = Livewire::test(AcquisitionDesk::class)->call('selectPixel', $pixel['id'])
            ->set('pixel.name', '')->call('savePixel')->assertReturned(false)->assertSet('pixel.name', '')
            ->call('restoreEditor', 'pixel')->assertReturned(true)->assertSet('pixel.name', $pixel['name']);
        $this->signIn();
        Livewire::test(AcquisitionDesk::class)->set('pixelId', $pixel['id'])
            ->set('pixel.name', 'Unauthorized edit')->call('restoreEditor', 'pixel')->assertReturned(false)
            ->assertSet('pixel.name', 'Unauthorized edit')->call('selectAssignment', 'pixel', $pixel['id'])
            ->assertSet('tab', 'links')->assertSet('shareId', '');
    }

    public function test_payment_display_uses_shared_exact_usd_format_without_float_conversion(): void
    {
        $this->signIn();
        [$pixel, $link] = $this->assets();
        DB::table('acquisition_receipts')->insert(['event_id' => AcquisitionStore::id(), 'payment_id' => AcquisitionStore::id(),
            'fingerprint' => hash('sha256', 'receipt_format_test'), 'link_id' => $link['id'], 'source_id' => null,
            'transaction_kind' => 'first_subscription', 'discount_applied' => false, 'amount_minor' => 1200, 'currency' => 'USD',
            'is_test' => true, 'occurred_at' => now(), 'attribution_snapshot' => null, 'source_status' => 'unattributed', 'received_at' => now()]);
        Livewire::test(AcquisitionDesk::class)->call('showSummary', $link['id'])->call('selectTab', 'payments')
            ->assertSee('$12.00 USD')->assertDontSee('USD 12.00');
        $this->assertSame(1200, UsdAmount::fromDecimal('12.00'));
        $this->assertSame('12.00', UsdAmount::toDecimal(1200));
    }
}
