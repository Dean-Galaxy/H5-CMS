<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Filament\Pages\AcquisitionDesk;
use Filament\Notifications\Notification;
use Livewire\Livewire;

final class DeskTest extends AcquisitionTestCase
{
    public function test_form_save_reopen_local_probe_link_edit_disable_and_summary(): void
    {
        $this->signIn();
        $page = Livewire::test(AcquisitionDesk::class)->assertOk()->assertSee('平台确认分别记录')
            ->assertSee('尚无可见像素')->set('pixel', $this->pixelInput('tiktok'))->call('savePixel')->assertHasNoErrors();
        $pixelId = $page->get('pixelId');
        $this->assertNotNull($pixelId);
        $page = Livewire::test(AcquisitionDesk::class)->call('selectPixel', $pixelId)->assertSet('pixel.version', 1)
            ->assertSet('pixel.platform', 'tiktok')->call('simulatePixel', $pixelId)->assertSee('模拟通过')
            ->call('loadCatalog')->assertSee('testing 片单样例')
            ->set('link.name', 'Test link')->set('link.platform', 'tiktok')->set('link.pixel_id', $pixelId)
            ->set('link.drama_id', array_key_first($this->dramas))->call('saveLink')->assertHasNoErrors();
        $linkId = $page->get('linkId');
        $this->assertNotNull($linkId);
        $page = Livewire::test(AcquisitionDesk::class)->call('selectLink', $linkId)->assertSet('link.name', 'Test link')
            ->set('link.name', 'Renamed link')->set('link.active', false)->call('saveLink')->assertHasNoErrors()->assertSet('link.version', 2)
            ->call('showSummary', $linkId)->assertSee('暂无对应付款事实')->assertSee('落地待 D05');
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
}
