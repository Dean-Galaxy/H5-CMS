<?php

namespace App\Modules\Acquisition\Services;

use App\Contracts\AcquisitionStaffDirectory;
use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Contracts\PromotableCatalog;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Acquisition\Sending\LocalSimulator;
use App\Modules\Acquisition\Sending\MetaAdapter;
use App\Modules\Acquisition\Sending\TikTokAdapter;
use App\Support\ApiProblem;
use App\Support\DependencyNotReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AssetManager
{
    public function __construct(private AssetAccess $access) {}

    public function pixels(): array
    {
        return $this->assetPage('pixel')['items'];
    }

    public function pixel(string $id): array
    {
        return $this->pixelView($this->access->get('pixel', $id));
    }

    public function savePixel(?string $id, array $input): array
    {
        $actor = $this->access->actor();
        $this->rejectUnknown($input, ['name', 'platform', 'external_id', 'environment', 'active', 'version']);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'], 'platform' => ['required', Rule::in(['meta', 'tiktok'])],
            'external_id' => ['required', 'string', 'max:64', ($input['platform'] ?? '') === 'meta' ? 'regex:/^[0-9]{5,30}$/' : 'regex:/^[A-Za-z0-9_-]{5,64}$/'],
            'environment' => ['required', Rule::in(['test', 'production'])], 'active' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ], [
            'name.required' => '请填写像素名称', 'name.string' => '像素名称必须是文字', 'name.max' => '像素名称不能超过100个字符',
            'platform.required' => '请选择投放平台', 'platform.in' => '请选择Meta或TikTok平台',
            'external_id.required' => '请填写平台像素编号', 'external_id.string' => '请以文字填写平台像素编号',
            'external_id.max' => '平台像素编号不能超过64个字符', 'external_id.regex' => '像素编号格式无效，请核对所选平台的编号',
            'environment.required' => '请选择资产用途', 'environment.in' => '请选择专用测试资产或正式资产',
            'active.required' => '请选择启用状态', 'active.boolean' => '启用状态无效，请重新选择',
            'version.required' => '缺少像素版本，请重新打开像素编辑', 'version.integer' => '像素版本无效，请重新打开像素编辑',
            'version.min' => '像素版本无效，请重新打开像素编辑',
        ])->validate();

        return DB::transaction(function () use ($id, $data, $actor) {
            $row = $id ? $this->access->get('pixel', $id, true) : null;
            $this->version($row, (int) $data['version']);
            if ($row && ($row->external_id !== $data['external_id'] || $row->platform !== $data['platform'] || $row->environment !== $data['environment'])) {
                throw new ApiProblem('DEPENDENCY_NOT_READY', 503, '资产编号、平台和环境变更等待 D05 规则；可另行登记资产');
            }
            $duplicate = DB::table('acquisition_pixels')->where('platform', $data['platform'])->where('external_id', $data['external_id'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
            if ($duplicate) {
                throw new ApiProblem('VALIDATION_FAILED', 422, '该平台编号不可重复登记，请由负责人核对授权');
            }
            $id ??= AcquisitionStore::id();
            $values = $data;
            $values['version']++;
            $values['updated_at'] = now();
            if ($row) {
                DB::table('acquisition_pixels')->where('id', $id)->update($values);
            } else {
                DB::table('acquisition_pixels')->insert($values + ['id' => $id, 'owner_id' => $actor->id, 'created_at' => now()]);
            }
            DB::table('acquisition_pixel_versions')->insert([
                'pixel_id' => $id, 'version' => $values['version'], 'platform' => $data['platform'],
                'external_id' => $data['external_id'], 'environment' => $data['environment'], 'created_at' => now(),
            ]);
            app(AuditRecorder::class)->record($row ? 'acquisition.pixel.updated' : 'acquisition.pixel.created', 'acquisition_pixel', $id, 'success',
                ['version' => $values['version'], 'active' => $data['active']]);

            return $this->pixel($id);
        }, 3);
    }

    public function links(): array
    {
        return $this->assetPage('link')['items'];
    }

    public function assetPage(string $type, int $page = 1): array
    {
        $query = $this->access->query($type);
        $total = $query->count();
        $last = max(1, (int) ceil($total / 50));
        $page = max(1, min($last, $page));
        $items = $query->orderByDesc('id')->forPage($page, 50)->get()->map(fn ($r) => $type === 'pixel' ? $this->pixelView($r) : $this->linkView($r))->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'last_page' => $last];
    }

    public function link(string $id): array
    {
        return $this->linkView($this->access->get('link', $id));
    }

    public function catalog(?string $cursor = null): array
    {
        $this->access->actor();
        app(IdentityContext::class)->requireStaff(Permission::PublishedCatalogRead);
        $page = app(PromotableCatalog::class)->promotable($cursor, 50, 'zh_CN');

        return $page->toArray();
    }

    public function saveLink(?string $id, array $input): array
    {
        $actor = $this->access->actor();
        $this->rejectUnknown($input, ['name', 'platform', 'drama_id', 'pixel_id', 'active', 'version']);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'], 'platform' => ['required', Rule::in(['meta', 'tiktok'])],
            'drama_id' => ['required', 'uuid'], 'pixel_id' => ['required', 'uuid'], 'active' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ], [
            'name.required' => '请填写推广名称', 'name.string' => '推广名称必须是文字', 'name.max' => '推广名称不能超过100个字符',
            'platform.required' => '请选择投放平台', 'platform.in' => '请选择Meta或TikTok平台',
            'drama_id.required' => '请选择已发布剧目', 'drama_id.uuid' => '剧目选择无效，请重新读取并选择已发布剧目',
            'pixel_id.required' => '请选择授权像素', 'pixel_id.uuid' => '像素选择无效，请重新选择授权像素',
            'active.required' => '请选择启用状态', 'active.boolean' => '启用状态无效，请重新选择',
            'version.required' => '缺少链接版本，请重新打开链接编辑', 'version.integer' => '链接版本无效，请重新打开链接编辑',
            'version.min' => '链接版本无效，请重新打开链接编辑',
        ])->validate();

        return DB::transaction(function () use ($id, $data, $actor) {
            $row = $id ? $this->access->get('link', $id, true) : null;
            $this->version($row, (int) $data['version']);
            if ($row && ($row->drama_id !== $data['drama_id'] || $row->pixel_id !== $data['pixel_id'] || $row->platform !== $data['platform'])) {
                throw new DependencyNotReady('D05：链接目标与像素变更规则');
            }
            // Stopping a stale/unpublished link must remain possible.
            if (! $row || (bool) $data['active']) {
                $pixel = $this->access->get('pixel', $data['pixel_id'], true);
                if (! $pixel->active || $pixel->platform !== $data['platform']) {
                    throw new ApiProblem('VALIDATION_FAILED', 422, '请选择已启用且平台一致的授权像素');
                }
                $drama = app(PromotableCatalog::class)->promotableDrama($data['drama_id'], 'zh_CN');
                if ($drama->country_code !== 'US' || $drama->published_episode_count < 1) {
                    throw new ApiProblem('NOT_FOUND', 404, '剧目当前不可投放');
                }
            }
            $values = $data;
            $values['version']++;
            $values['updated_at'] = now();
            if (isset($drama)) {
                $values['drama_title'] = $drama->title;
            }
            $id ??= AcquisitionStore::id();
            if ($row) {
                DB::table('acquisition_links')->where('id', $id)->update($values);
            } else {
                DB::table('acquisition_links')->insert($values + ['id' => $id, 'code' => Str::random(32), 'owner_id' => $actor->id, 'created_at' => now()]);
            }
            app(AuditRecorder::class)->record($row ? 'acquisition.link.updated' : 'acquisition.link.created', 'acquisition_link', $id, 'success',
                ['version' => $values['version'], 'active' => $data['active']]);

            return $this->link($id);
        }, 3);
    }

    public function share(string $type, string $id, int $version, array $staffIds): void
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        Validator::make(['staff_ids' => $staffIds], ['staff_ids' => ['present', 'array', 'max:200'],
            'staff_ids.*' => ['required', 'uuid', 'distinct']], [
                'staff_ids.present' => '请重新读取可分配人员后保存', 'staff_ids.array' => '人员选择无效，请重新读取可分配人员',
                'staff_ids.max' => '一次最多分配200位员工', 'staff_ids.*.required' => '人员选择无效，请重新选择员工',
                'staff_ids.*.uuid' => '人员选择无效，请重新读取可分配人员', 'staff_ids.*.distinct' => '员工不能重复选择，请重新读取名单后选择',
            ])->validate();
        DB::transaction(function () use ($type, $id, $version, $staffIds) {
            $row = $this->access->get($type, $id, true);
            $this->version($row, $version);
            // Every selected ID is revalidated through Identity, including IDs from older pages.
            foreach ($staffIds as $staffId) {
                $candidate = app(AcquisitionStaffDirectory::class)->candidate($staffId);
                if (! $candidate->active || $candidate->role !== Role::Acquisition || $candidate->id !== $staffId) {
                    throw new ApiProblem('NOT_FOUND', 404, '人员当前不再符合投流分配条件');
                }
            }
            DB::table('acquisition_assignments')->where('resource_type', $type)->where('resource_id', $id)->delete();
            foreach ($staffIds as $staffId) {
                DB::table('acquisition_assignments')->insert(['resource_type' => $type, 'resource_id' => $id, 'staff_id' => $staffId, 'created_at' => now()]);
            }
            DB::table($this->access->table($type))->where('id', $id)->update(['version' => $version + 1, 'updated_at' => now()]);
            if ($type === 'pixel') {
                DB::table('acquisition_pixel_versions')->insert(['pixel_id' => $id, 'version' => $version + 1,
                    'platform' => $row->platform, 'external_id' => $row->external_id, 'environment' => $row->environment, 'created_at' => now()]);
            }
            app(AuditRecorder::class)->record('acquisition.'.$type.'.assigned', 'acquisition_'.$type, $id, 'success', ['version' => $version + 1]);
        }, 3);
    }

    public function staffCandidates(?string $cursor = null): array
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);

        return app(AcquisitionStaffDirectory::class)->candidates($cursor, 50)->toArray();
    }

    public function assignment(string $type, string $id): array
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);
        $row = $this->access->get($type, $id);
        $selected = [];
        foreach (DB::table('acquisition_assignments')->where('resource_type', $type)->where('resource_id', $id)->pluck('staff_id') as $staffId) {
            try {
                $candidate = app(AcquisitionStaffDirectory::class)->candidate($staffId);
                $selected[] = ['id' => $candidate->id, 'name' => $candidate->name, 'available' => true];
            } catch (ApiProblem $e) {
                if ($e->errorCode !== 'NOT_FOUND') {
                    throw $e;
                }
                // Keep an unavailable selection visible so it can be deliberately removed.
                $selected[] = ['id' => $staffId, 'name' => '已失去分配资格（请取消勾选）', 'available' => false];
            }
        }

        return ['name' => $row->name, 'version' => $row->version, 'selected' => $selected];
    }

    public function simulatePixel(string $id): void
    {
        $pixel = $this->access->get('pixel', $id);
        if (! app()->environment('local', 'testing') || $pixel->environment !== 'test' || ! $pixel->active) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '本地模拟只允许启用的测试资产');
        }
        $delivery = (object) ['event_key' => 'probe_'.AcquisitionStore::id(), 'platform' => $pixel->platform,
            'external_id' => $pixel->external_id, 'environment' => 'test'];
        $receipt = (object) ['occurred_at' => now()->toIso8601String(), 'amount_minor' => 300,
            'transaction_kind' => 'first_subscription', 'discount_applied' => true];
        $adapter = $pixel->platform === 'meta' ? new MetaAdapter : new TikTokAdapter;
        $payload = $adapter->payload($delivery, $receipt, ['page_url' => rtrim(config('app.url'), '/').'/acquisition-local-simulation',
            'ip' => '192.0.2.1', 'user_agent' => 'H5 explicit local simulation'], 'LOCAL_SIMULATION');
        $result = app(LocalSimulator::class)->send($delivery, $payload);
        if ($result->state !== 'simulated') {
            throw new ApiProblem('VALIDATION_FAILED', 422, '本地模拟未通过');
        }
        DB::table('acquisition_pixels')->where('id', $id)->update(['local_status' => 'simulated', 'updated_at' => now()]);
        app(AuditRecorder::class)->record('acquisition.pixel.local_probe', 'acquisition_pixel', $id, 'simulated');
    }

    public function summary(string $linkId): array
    {
        $this->access->get('link', $linkId, permission: Permission::AcquisitionSummary);
        $rows = DB::table('acquisition_receipts')->where('link_id', $linkId)->select('is_test', 'transaction_kind', 'currency')
            ->selectRaw('COUNT(*) AS payments, SUM(amount_minor) AS amount_minor')->groupBy('is_test', 'transaction_kind', 'currency')->get();
        $deliveries = DB::table('acquisition_deliveries')->where('link_id', $linkId)->select('state', 'last_error')->selectRaw('COUNT(*) AS count')->groupBy('state', 'last_error')->get();

        return ['payments' => $rows->map(fn ($r) => ['is_test' => (bool) $r->is_test, 'transaction_kind' => $r->transaction_kind,
            'currency' => $r->currency, 'payments' => (int) $r->payments, 'amount_minor' => (int) $r->amount_minor])->all(),
            'deliveries' => $deliveries->map(fn ($r) => ['state' => $r->state, 'reason' => $r->last_error, 'count' => (int) $r->count])->all(),
            'visits' => null, 'visits_status' => 'DEPENDENCY_NOT_READY',
            'definition' => '本站核实付款总额，未扣退款；测试与真实分列；不是平台归因、ROAS 或真实观看'];
    }

    public function retry(string $linkId): int
    {
        $this->access->get('link', $linkId);
        // Does not send here, reset attempts, change event id, or requeue accepted events.
        $count = DB::table('acquisition_deliveries')->where('link_id', $linkId)->whereIn('state', ['retry', 'blocked'])
            ->where('attempts', '<', config('acquisition.max_attempts'))->update(['state' => 'pending', 'next_attempt_at' => now(), 'updated_at' => now()]);
        app(AuditRecorder::class)->record('acquisition.delivery.retry_requested', 'acquisition_link', $linkId, 'success');

        return $count;
    }

    private function pixelView(object $r): array
    {
        $result = array_intersect_key((array) $r, array_flip(['id', 'name', 'platform', 'external_id', 'environment', 'active', 'version', 'owner_id', 'local_status']));
        $result['assigned_staff_ids'] = DB::table('acquisition_assignments')->where('resource_type', 'pixel')->where('resource_id', $r->id)->pluck('staff_id')->all();
        $result['affected_links'] = $this->access->query('link')->where('pixel_id', $r->id)->count();
        $result['external_status'] = '待账号持有人联调';

        return $result;
    }

    private function linkView(object $r): array
    {
        return array_intersect_key((array) $r, array_flip(['id', 'name', 'platform', 'drama_id', 'drama_title', 'pixel_id', 'active', 'version', 'owner_id']))
            + ['url' => rtrim(config('app.url'), '/').'/r/'.$r->code, 'landing_status' => 'D05_PENDING'];
    }

    private function version(?object $row, int $version): void
    {
        if ($version !== ($row?->version ?? 0)) {
            throw new ApiProblem('STALE_VERSION', 409, '版本已变更，请重新打开后保存');
        }
    }

    private function rejectUnknown(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw new ApiProblem('VALIDATION_FAILED', 422, '存在不允许的配置字段；不能粘贴脚本或指定身份');
        }
    }
}
