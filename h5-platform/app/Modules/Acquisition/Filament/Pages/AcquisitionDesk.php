<?php

namespace App\Modules\Acquisition\Filament\Pages;

use App\Contracts\IdentityContext;
use App\Enums\Role;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Modules\Acquisition\Services\AssetManager;
use App\Support\ApiProblem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

final class AcquisitionDesk extends Page
{
    protected static ?string $title = '投流链接与像素';

    protected static ?string $slug = 'acquisition-desk';

    protected string $view = 'acquisition.desk';

    public ?string $pixelId = null;

    public array $pixel = ['name' => '', 'platform' => 'meta', 'external_id' => '', 'environment' => 'test', 'active' => 1, 'version' => 0];

    public ?string $linkId = null;

    public array $link = ['name' => '', 'platform' => 'meta', 'drama_id' => '', 'pixel_id' => '', 'active' => 1, 'version' => 0];

    public ?string $summaryId = null;

    // Replace the current URL so reload restores context and Back still returns to the prior page.
    #[Url(as: 'tab', except: 'links')]
    public string $tab = 'links';

    public int $pixelPage = 1;

    public int $linkPage = 1;

    public array $catalog = [];

    public ?string $catalogCursor = null;

    public string $catalogMessage = '点击读取 Content 可投放片单';

    public string $shareType = 'pixel';

    public string $shareId = '';

    public int $shareVersion = 0;

    public array $staffIds = [];

    public array $staffCandidates = [];

    public ?string $staffCursor = null;

    public string $shareName = '';

    public string $staffMessage = '选择资产后读取人员；名单只包含可分配的启用投流员工';

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return (bool) (config('h5.modules.acquisition.ready') && $user?->active && in_array($user->role, [Role::Owner, Role::Acquisition], true));
    }

    public function mount(): void
    {
        $this->normalizeTab();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (! in_array($this->tab, ['links', 'pixels', 'payments', 'deliveries', 'assignments'], true)
            || ($this->tab === 'assignments' && app(IdentityContext::class)->staff()?->role !== Role::Owner)) {
            $this->tab = 'links';
        }
    }

    public function selectTab(string $tab): void
    {
        if (! in_array($tab, ['links', 'pixels', 'payments', 'deliveries', 'assignments'], true)) {
            return;
        }

        if ($tab === 'assignments' && app(IdentityContext::class)->staff()?->role !== Role::Owner) {
            return;
        }

        $this->tab = $tab;
    }

    public function selectPixel(string $id): void
    {
        $this->attempt(function () use ($id) {
            $data = app(AssetManager::class)->pixel($id);
            $this->pixelId = $id;
            $this->pixel = array_intersect_key($data, $this->pixel);
            // Native select options are 0/1. A boolean false serializes as "false"
            // and would show the first option after a Livewire re-render.
            $this->pixel['active'] = (int) (bool) $data['active'];
        });
    }

    public function newPixel(): void
    {
        app(AssetAccess::class)->actor();
        $this->reset('pixelId', 'pixel');
    }

    public function savePixel(): bool
    {
        return $this->attempt(function () {
            $data = app(AssetManager::class)->savePixel($this->pixelId, $this->pixel);
            $this->selectPixel($data['id']);
            Notification::make()->title('像素登记已保存；平台授权与接收仍待联调')->success()->send();
        });
    }

    public function simulatePixel(string $id): void
    {
        $this->attempt(function () use ($id) {
            app(AssetManager::class)->simulatePixel($id);
            Notification::make()->title('本地模拟通过；未向平台发送，也未增加收入')->success()->send();
        });
    }

    public function loadCatalog(): void
    {
        $this->attempt(function () {
            $page = app(AssetManager::class)->catalog($this->catalogCursor);
            $this->catalog = collect(array_merge($this->catalog, $page['items']))->unique('id')->values()->all();
            $this->catalogCursor = $page['next_cursor'];
            $this->catalogMessage = $this->catalog === [] ? 'Content 当前没有可投放的已发布剧目' : '已读取 '.count($this->catalog).' 部剧；保存时会重新核对';
        });
    }

    public function newLink(): void
    {
        app(AssetAccess::class)->actor();
        $this->reset('linkId', 'link');
    }

    public function selectLink(string $id): void
    {
        $this->attempt(function () use ($id) {
            $data = app(AssetManager::class)->link($id);
            $this->linkId = $id;
            $this->link = array_intersect_key($data, $this->link);
            $this->link['active'] = (int) (bool) $data['active'];
            if (! in_array($data['drama_id'], array_column($this->catalog, 'id'), true)) {
                $this->catalog[] = ['id' => $data['drama_id'], 'title' => $data['drama_title'].'（已保存，提交时复核）'];
            }
        });
    }

    public function saveLink(): bool
    {
        return $this->attempt(function () {
            $data = app(AssetManager::class)->saveLink($this->linkId, $this->link);
            $this->selectLink($data['id']);
            Notification::make()->title('链接已保存；落地与来源启用等待 D05 接收')->success()->send();
        });
    }

    public function restoreEditor(string $editor): bool
    {
        return $this->attempt(function () use ($editor) {
            match ($editor) {
                'pixel' => $this->restorePixel(),
                'link' => $this->restoreLink(),
                'assignment' => $this->restoreAssignment(),
                default => throw new ApiProblem('VALIDATION_FAILED', 422, '编辑区无效'),
            };
        });
    }

    public function showSummary(string $id): void
    {
        $this->attempt(function () use ($id) {
            app(AssetManager::class)->summary($id);
            $this->summaryId = $id;
            $this->tab = 'payments';
        });
    }

    public function retry(string $id): void
    {
        $this->attempt(function () use ($id) {
            $count = app(AssetManager::class)->retry($id);
            Notification::make()->title('已重新排队 '.$count.' 项；发送前仍检查隐私和环境')->success()->send();
        });
    }

    public function share(): bool
    {
        return $this->attempt(function () {
            app(AssetManager::class)->share($this->shareType, $this->shareId, $this->shareVersion, $this->staffIds);
            $this->selectAssignment($this->shareType, $this->shareId);
            Notification::make()->title('共享分配已保存；被移除人员的访问权立即重新核验')->success()->send();
        });
    }

    public function selectAssignment(string $type, string $id): void
    {
        $this->attempt(function () use ($type, $id) {
            $data = app(AssetManager::class)->assignment($type, $id);
            $this->reset('staffCandidates', 'staffCursor', 'staffMessage');
            $this->shareType = $type;
            $this->shareId = $id;
            $this->shareVersion = $data['version'];
            $this->shareName = $data['name'];
            $this->staffIds = array_column($data['selected'], 'id');
            $this->staffCandidates = $data['selected'];
            $this->tab = 'assignments';
        });
    }

    public function loadStaff(): void
    {
        $this->attempt(function () {
            $page = app(AssetManager::class)->staffCandidates($this->staffCursor);
            $this->staffCandidates = collect(array_merge($this->staffCandidates, $page['items']))->unique('id')->values()->all();
            $this->staffCursor = $page['next_cursor'];
            $this->staffMessage = $page['items'] === [] ? 'Identity 当前没有其他可分配人员' : '已读取名单；保存时重新核对资格';
        });
    }

    protected function getViewData(): array
    {
        $this->normalizeTab();
        $manager = app(AssetManager::class);
        $pixels = $manager->assetPage('pixel', $this->pixelPage);
        $links = $manager->assetPage('link', $this->linkPage);
        $options = $pixels['items'];
        $selected = $this->link['pixel_id'] ?? '';
        if ($selected !== '' && ! in_array($selected, array_column($options, 'id'), true)) {
            try {
                $options[] = $manager->pixel($selected);
            } catch (ApiProblem $e) {
                if ($e->errorCode !== 'NOT_FOUND') {
                    throw $e;
                }
                // Do not discard the saved ID merely because its authorization changed.
                $options[] = ['id' => $selected, 'name' => '已选像素当前无权使用（仅可停用已有链接）', 'platform' => '', 'active' => false];
            }
        }

        $owner = app(IdentityContext::class)->staff()?->role === Role::Owner;
        $tabs = [['id' => 'links', 'label' => '推广链接'], ['id' => 'pixels', 'label' => '像素资产'],
            ['id' => 'payments', 'label' => '本站付款汇总'], ['id' => 'deliveries', 'label' => '发送状态']];
        if ($owner) {
            $tabs[] = ['id' => 'assignments', 'label' => '负责人分配'];
        }

        return ['pixels' => $pixels['items'], 'links' => $links['items'], 'pixelOptions' => $options,
            'pixelPagination' => $pixels, 'linkPagination' => $links,
            'summary' => $this->summaryId ? $manager->summary($this->summaryId) : null,
            'owner' => $owner, 'tabs' => $tabs];
    }

    private function attempt(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (ApiProblem $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }
    }

    private function restorePixel(): void
    {
        if (! $this->pixelId) {
            $this->reset('pixel');

            return;
        }
        $data = app(AssetManager::class)->pixel($this->pixelId);
        $this->pixel = array_intersect_key($data, $this->pixel);
        $this->pixel['active'] = (int) (bool) $data['active'];
    }

    private function restoreLink(): void
    {
        if (! $this->linkId) {
            $this->reset('link');

            return;
        }
        $data = app(AssetManager::class)->link($this->linkId);
        $this->link = array_intersect_key($data, $this->link);
        $this->link['active'] = (int) (bool) $data['active'];
    }

    private function restoreAssignment(): void
    {
        if (! $this->shareId) {
            $this->reset('shareType', 'shareId', 'shareVersion', 'staffIds');

            return;
        }
        $data = app(AssetManager::class)->assignment($this->shareType, $this->shareId);
        $this->shareVersion = $data['version'];
        $this->shareName = $data['name'];
        $this->staffIds = array_column($data['selected'], 'id');
        $this->staffCandidates = $data['selected'];
        $this->staffCursor = null;
    }
}
