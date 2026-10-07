<?php

namespace App\Modules\Acquisition\Filament\Pages;

use App\Enums\Role;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Modules\Acquisition\Services\AssetManager;
use App\Support\ApiProblem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

final class AcquisitionDesk extends Page
{
    protected static ?string $title = '投流链接与像素';

    protected static ?string $slug = 'acquisition-desk';

    protected string $view = 'acquisition.desk';

    public ?string $pixelId = null;

    public array $pixel = ['name' => '', 'platform' => 'meta', 'external_id' => '', 'environment' => 'test', 'active' => true, 'version' => 0];

    public ?string $linkId = null;

    public array $link = ['name' => '', 'platform' => 'meta', 'drama_id' => '', 'pixel_id' => '', 'active' => true, 'version' => 0];

    public ?string $summaryId = null;

    public array $catalog = [];

    public ?string $catalogCursor = null;

    public string $catalogMessage = '点击读取 Content 可投放片单';

    public string $shareType = 'pixel';

    public string $shareId = '';

    public int $shareVersion = 0;

    public array $staffIds = [];

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return (bool) (config('h5.modules.acquisition.ready') && $user?->active && in_array($user->role, [Role::Owner, Role::Acquisition], true));
    }

    public function selectPixel(string $id): void
    {
        $this->attempt(function () use ($id) {
            $data = app(AssetManager::class)->pixel($id);
            $this->pixelId = $id;
            $this->pixel = array_intersect_key($data, $this->pixel);
        });
    }

    public function newPixel(): void
    {
        app(AssetAccess::class)->actor();
        $this->reset('pixelId', 'pixel');
    }

    public function savePixel(): void
    {
        $this->attempt(function () {
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
            if (! in_array($data['drama_id'], array_column($this->catalog, 'id'), true)) {
                $this->catalog[] = ['id' => $data['drama_id'], 'title' => $data['drama_title'].'（已保存，提交时复核）'];
            }
        });
    }

    public function saveLink(): void
    {
        $this->attempt(function () {
            $data = app(AssetManager::class)->saveLink($this->linkId, $this->link);
            $this->selectLink($data['id']);
            Notification::make()->title('链接已保存；落地与来源启用等待 D05 接收')->success()->send();
        });
    }

    public function showSummary(string $id): void
    {
        $this->attempt(function () use ($id) {
            app(AssetManager::class)->summary($id);
            $this->summaryId = $id;
        });
    }

    public function retry(string $id): void
    {
        $this->attempt(function () use ($id) {
            $count = app(AssetManager::class)->retry($id);
            Notification::make()->title('已重新排队 '.$count.' 项；发送前仍检查隐私和环境')->success()->send();
        });
    }

    public function share(): void
    {
        $this->attempt(fn () => app(AssetManager::class)->share($this->shareType, $this->shareId, $this->shareVersion, $this->staffIds));
    }

    protected function getViewData(): array
    {
        $manager = app(AssetManager::class);

        return ['pixels' => $manager->pixels(), 'links' => $manager->links(),
            'summary' => $this->summaryId ? $manager->summary($this->summaryId) : null,
            'owner' => auth('staff')->user()?->role === Role::Owner];
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (ApiProblem $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
