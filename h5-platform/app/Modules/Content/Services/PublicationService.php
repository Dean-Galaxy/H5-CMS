<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\Plan;
use App\Modules\Content\Models\Publication;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublicationService
{
    public function previewLaunchNow(string $dramaId, array $input): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['mode' => 'required|in:completed,updating', 'interval_days' => 'required|integer|in:1,2,7',
            'batch_size' => 'required|integer|in:1,2,5,10,20', 'next_at' => 'nullable|date_format:Y-m-d\\TH:i:sP']);

        return ContentGuard::mutate(function () use ($dramaId, $data) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            if ($drama->state === 'withdrawn') {
                throw new ApiProblem('STALE_VERSION', 409, '下架剧目需先明确重新上架');
            }
            $plan = $drama->plan;
            $from = $this->nextNumber($drama);
            $through = $data['mode'] === 'completed' ? (int) ($drama->total_episodes ?? 0) : min((int) ($drama->total_episodes ?? 0), $from + $data['batch_size'] - 1);
            $next = null;
            if ($data['mode'] === 'updating' && $drama->total_episodes && $through < $drama->total_episodes) {
                if (empty($data['next_at'])) {
                    throw ValidationException::withMessages(['next_at' => '更新中首批立即发布时，必须同时确认下一批北京时间']);
                }
                $next = $this->future($data['next_at']);
            }
            $reason = $this->blockReason($drama, $from, $through, now());
            $result = ['drama_id' => $drama->id, 'drama_version' => $drama->version, 'plan_version' => $plan->version,
                'mode' => $data['mode'], 'interval_days' => $data['interval_days'], 'batch_size' => $data['batch_size'],
                'from' => $from, 'through' => $through, 'count' => max(0, $through - $from + 1),
                'next_at' => $next?->toIso8601ZuluString(), 'blocked_reason' => $reason];
            $result['confirmation'] = hash_hmac('sha256', json_encode($result, JSON_THROW_ON_ERROR), config('app.key'));

            return $result;
        });
    }

    public function launchNow(string $dramaId, array $input): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['plan_version' => 'required|integer|min:1', 'confirmation' => 'required|string|size:64',
            'mode' => 'required|in:completed,updating', 'interval_days' => 'required|integer|in:1,2,7', 'batch_size' => 'required|integer|in:1,2,5,10,20',
            'next_at' => 'nullable|date_format:Y-m-d\\TH:i:sP']);

        return ContentGuard::mutate(function () use ($dramaId, $data) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            ContentGuard::version($plan, $data['plan_version']);
            $preview = $this->previewLaunchNow($dramaId, $data);
            if (! hash_equals($preview['confirmation'], $data['confirmation'])) {
                throw new ApiProblem('STALE_VERSION', 409, '发布范围或计划版本已变化，请重新预览');
            }
            if ($preview['blocked_reason']) {
                throw ValidationException::withMessages(['publication' => $preview['blocked_reason']]);
            }
            $numbers = $this->publish($drama, $preview['from'], $preview['through']);
            $next = $preview['next_at'] ? CarbonImmutable::parse($preview['next_at'])->utc() : null;
            $plan->fill(['mode' => $data['mode'], 'interval_days' => $data['interval_days'], 'batch_size' => $data['batch_size'],
                'start_number' => $this->nextNumber($drama), 'launch_at' => now(), 'next_at' => $next,
                'state' => $this->completed($drama) ? 'finished' : 'active', 'version' => $plan->version + 1, 'last_error' => null])->save();
            Publication::create(['drama_id' => $dramaId, 'plan_id' => $plan->id, 'plan_version' => $plan->version,
                'scheduled_at' => null, 'executed_at' => now(), 'source' => 'manual_initial', 'result' => 'published', 'episode_numbers' => $numbers]);
            ContentGuard::changed('plan.launched_now', 'content_plan', $plan->id, $plan->version);

            return ['published' => $numbers, 'published_count' => $drama->episodes()->whereNotNull('published_at')->count(),
                'next_number' => $this->nextNumber($drama), 'next_at' => $next?->toIso8601ZuluString(), 'plan_state' => $plan->state];
        });
    }

    public function previewNextBatch(string $dramaId): array
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($dramaId) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            if ($plan->state !== 'active' || $plan->mode !== 'updating' || ! $plan->next_at || $drama->state === 'withdrawn') {
                throw new ApiProblem('STALE_VERSION', 409, '仅可提前发布正在运行的更新中计划下一批');
            }
            if ($plan->next_at <= now()) {
                throw new ApiProblem('DEPENDENCY_NOT_READY', 409, '下一排期已到期，按当前定时状态处理，不提前改写排期');
            }
            [$from, $through] = $this->range($drama, $plan);
            $result = ['drama_id' => $drama->id, 'drama_version' => $drama->version, 'plan_version' => $plan->version,
                'from' => $from, 'through' => $through, 'next_at' => $plan->next_at->toIso8601ZuluString(),
                'blocked_reason' => $this->blockReason($drama, $from, $through, now())];
            $result['confirmation'] = hash_hmac('sha256', json_encode($result, JSON_THROW_ON_ERROR), config('app.key'));

            return $result;
        });
    }

    public function publishNextBatch(string $dramaId, array $input): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['plan_version' => 'required|integer|min:1', 'confirmation' => 'required|string|size:64']);

        return ContentGuard::mutate(function () use ($dramaId, $data) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            ContentGuard::version($drama->plan, $data['plan_version']);
            $preview = $this->previewNextBatch($dramaId);
            if (! hash_equals($preview['confirmation'], $data['confirmation'])) {
                throw new ApiProblem('STALE_VERSION', 409, '计划、游标或下一时间已变化，请重新预览');
            }
            if ($preview['blocked_reason']) {
                throw ValidationException::withMessages(['publication' => $preview['blocked_reason']]);
            }
            $plan = $drama->plan;
            $numbers = $this->publish($drama, $preview['from'], $preview['through']);
            $done = $this->completed($drama);
            $plan->update(['state' => $done ? 'finished' : 'active', 'start_number' => $this->nextNumber($drama),
                // The previously confirmed future slot now belongs to the next batch.
                'next_at' => $done ? null : CarbonImmutable::parse($preview['next_at'])->utc(), 'version' => $plan->version + 1, 'last_error' => null]);
            Publication::create(['drama_id' => $dramaId, 'plan_id' => $plan->id, 'plan_version' => $plan->version,
                'scheduled_at' => null, 'executed_at' => now(), 'source' => 'manual_next_batch', 'result' => 'published', 'episode_numbers' => $numbers]);
            ContentGuard::changed('plan.batch_published_early', 'content_plan', $plan->id, $plan->version);

            return ['published' => $numbers, 'published_count' => $drama->episodes()->whereNotNull('published_at')->count(),
                'next_number' => $this->nextNumber($drama), 'next_at' => $plan->next_at?->toIso8601ZuluString(), 'plan_state' => $plan->state];
        });
    }

    /** Preview proposed values on a detached relation: no save, audit or version change. */
    public function previewSchedule(string $dramaId, array $input): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'mode' => 'required|in:completed,updating',
            'interval_days' => 'required|integer|in:1,2,7', 'batch_size' => 'required|integer|in:1,2,5,10,20',
            'launch_at' => 'required|date_format:Y-m-d\\TH:i:sP']);

        return ContentGuard::mutate(function () use ($dramaId, $data) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            ContentGuard::version($drama->plan, $data['version']);
            $plan = clone $drama->plan;
            $plan->fill($data);
            $plan->launch_at = $this->future($data['launch_at']);
            $plan->start_number = $this->nextNumber($drama);
            $drama->setRelation('plan', $plan);

            return $this->previewData($drama);
        });
    }

    /** The final confirmation atomically saves and activates the previewed values. */
    public function schedule(string $dramaId, array $input): Plan
    {
        ContentGuard::staff();
        $confirmation = ContentGuard::validate($input, ['confirmation' => 'required|string|size:64'])['confirmation'];

        return ContentGuard::mutate(function () use ($dramaId, $input, $confirmation) {
            $preview = $this->previewSchedule($dramaId, $input);
            if (! hash_equals($preview['confirmation'], $confirmation)) {
                throw new ApiProblem('STALE_VERSION', 409, '安排或内容已变化，请重新预览');
            }
            $this->savePlan($dramaId, [...$input, 'start_number' => $preview['from']]);
            $saved = $this->preview($dramaId);

            return $this->activate($dramaId, ['version' => $saved['plan_version'], 'confirmation' => $saved['confirmation']]);
        });
    }

    public function savePlan(string $dramaId, array $input): Plan
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'mode' => 'required|in:completed,updating',
            'interval_days' => 'required|integer|in:1,2,7', 'batch_size' => 'required|integer|in:1,2,5,10,20',
            'start_number' => 'required|integer|min:1|max:10000', 'launch_at' => 'required|date_format:Y-m-d\\TH:i:sP']);
        $at = CarbonImmutable::parse($data['launch_at'])->utc();
        if ($at->second !== 0) {
            throw ValidationException::withMessages(['launch_at' => '发布时刻精确到分钟，秒必须为 00']);
        }

        return ContentGuard::mutate(function () use ($dramaId, $data, $at) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            ContentGuard::version($plan, $data['version']);
            if ($plan->state === 'active') {
                throw new ApiProblem('STALE_VERSION', 409, '请先确认暂停并修改，运行中的计划不能直接保存为草稿');
            }
            if ($data['start_number'] !== $this->nextNumber($drama)) {
                throw ValidationException::withMessages(['start_number' => '起点必须为下一未公开集，不能跳过缺集']);
            }
            unset($data['version']);
            $plan->fill($data)->fill(['launch_at' => $at, 'next_at' => null, 'state' => $drama->state === 'withdrawn' ? 'disabled' : 'draft', 'version' => $plan->version + 1, 'last_error' => null])->save();
            ContentGuard::changed('plan.saved', 'content_plan', $plan->id, $plan->version);

            return $plan;
        });
    }

    public function preview(string $dramaId, ?int $manualThrough = null): array
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($dramaId, $manualThrough) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);

            return $this->previewData($drama, $manualThrough);
        });
    }

    private function previewData(DramaRecord $drama, ?int $manualThrough = null): array
    {
        $plan = $drama->plan;
        [$from, $through] = $this->range($drama, $plan, $manualThrough);
        $at = $manualThrough === null ? $plan->launch_at : now();
        $error = $this->blockReason($drama, $from, $through, $at);
        $data = ['drama_id' => $drama->id, 'drama_version' => $drama->version, 'plan_version' => $plan->version,
            'manual' => $manualThrough !== null, 'from' => $from, 'through' => $through, 'count' => max(0, $through - $from + 1), 'mode' => $plan->mode,
            'launch_at' => $plan->launch_at->toIso8601ZuluString(), 'interval_days' => $plan->interval_days,
            'batch_size' => $plan->batch_size, 'total_episodes' => $drama->total_episodes, 'blocked_reason' => $error];
        $data['confirmation'] = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));

        return $data;
    }

    public function activate(string $dramaId, array $input): Plan
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'confirmation' => 'required|string|size:64']);

        return ContentGuard::mutate(function () use ($dramaId, $data) {
            $drama = DramaRecord::findOrFail($dramaId);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            ContentGuard::version($plan, $data['version']);
            $preview = $this->previewData($drama);
            if (! hash_equals($preview['confirmation'], $data['confirmation'])) {
                throw new ApiProblem('STALE_VERSION', 409, '发布范围已变化，请重新预览');
            }
            if ($drama->state === 'withdrawn') {
                throw new ApiProblem('STALE_VERSION', 409, '下架剧目需明确重新上架方案');
            }
            if ($plan->launch_at <= now()) {
                throw ValidationException::withMessages(['launch_at' => '排期已过，请重新确认下一次发布时间']);
            }
            // Pending media may be scheduled, but will block the entire occurrence at execution.
            $plan->update(['state' => 'active', 'next_at' => $plan->launch_at, 'version' => $plan->version + 1, 'last_error' => null]);
            ContentGuard::changed('plan.activated', 'content_plan', $plan->id, $plan->version);

            return $plan;
        });
    }

    public function pause(string $id, int $version): Plan
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($id, $version) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            ContentGuard::version($plan, $version);
            if ($plan->state !== 'active') {
                throw new ApiProblem('STALE_VERSION', 409, '当前计划不在运行中');
            }
            $plan->update(['state' => 'paused', 'next_at' => null, 'version' => $plan->version + 1]);
            ContentGuard::changed('plan.paused', 'content_plan', $plan->id, $plan->version);

            return $plan;
        });
    }

    public function resume(string $id, array $input): Plan
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'next_at' => 'required|date_format:Y-m-d\\TH:i:sP']);
        $next = $this->future($data['next_at']);

        return ContentGuard::mutate(function () use ($id, $data, $next) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            $plan = $drama->plan;
            ContentGuard::version($plan, $data['version']);
            if ($drama->state === 'withdrawn' || ! in_array($plan->state, ['paused', 'missed_pending_policy'], true)) {
                throw new ApiProblem('STALE_VERSION', 409, '当前状态不能直接恢复');
            }
            $plan->update(['state' => 'active', 'next_at' => $next, 'version' => $plan->version + 1, 'start_number' => $this->nextNumber($drama), 'last_error' => null]);
            ContentGuard::changed('plan.resumed', 'content_plan', $plan->id, $plan->version);

            return $plan;
        });
    }

    public function manual(string $id, array $input): array
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'through' => 'required|integer|min:1|max:10000', 'confirmation' => 'required|string|size:64']);

        return ContentGuard::mutate(function () use ($id, $data) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            ContentGuard::version($drama, $data['version']);
            if ($drama->state === 'withdrawn') {
                throw new ApiProblem('STALE_VERSION', 409, '先明确重新上架方案，再发布');
            }
            $preview = $this->previewData($drama, $data['through']);
            if (! hash_equals($preview['confirmation'], $data['confirmation'])) {
                throw new ApiProblem('STALE_VERSION', 409, '发布范围已变化，请重新预览');
            }
            if ($preview['blocked_reason']) {
                throw ValidationException::withMessages(['publication' => $preview['blocked_reason']]);
            }
            $numbers = $this->publish($drama, $preview['from'], $preview['through']);
            $plan = $drama->plan;
            $plan->version++;
            $plan->start_number = $this->nextNumber($drama);
            if ($this->completed($drama)) {
                $plan->state = 'finished';
                $plan->next_at = null;
            }
            $plan->save();
            Publication::create(['drama_id' => $id, 'source' => 'manual', 'result' => 'published', 'episode_numbers' => $numbers, 'executed_at' => now()]);

            return ['published' => $numbers, 'drama_version' => $drama->version];
        });
    }

    public function withdraw(string $id, int $version): DramaRecord
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($id, $version) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            ContentGuard::version($drama, $version);
            $drama->update(['state' => 'withdrawn', 'version' => $drama->version + 1, 'access_version' => $drama->access_version + 1]);
            $drama->plan->update(['state' => 'disabled', 'next_at' => null, 'version' => $drama->plan->version + 1]);
            ContentGuard::changed('drama.withdrawn', 'content_drama', $id, $drama->version);

            return $drama;
        });
    }

    public function relist(string $id, array $input): DramaRecord
    {
        ContentGuard::staff();
        $data = ContentGuard::validate($input, ['version' => 'required|integer|min:1', 'choice' => 'required|in:restore,reset', 'next_at' => 'required_if:choice,restore|nullable|date_format:Y-m-d\\TH:i:sP']);
        $next = $data['choice'] === 'restore' ? $this->future($data['next_at']) : null;

        return ContentGuard::mutate(function () use ($id, $data, $next) {
            $drama = DramaRecord::findOrFail($id);
            ContentGuard::staff($drama);
            ContentGuard::version($drama, $data['version']);
            if ($drama->state !== 'withdrawn') {
                throw new ApiProblem('STALE_VERSION', 409, '当前剧目未下架');
            }
            $count = $drama->episodes()->whereNotNull('published_at')->count();
            if ($count && ($reason = $this->blockReason($drama, 1, $count, now()))) {
                throw ValidationException::withMessages(['publication' => $reason]);
            }
            $drama->update(['state' => $count ? 'published' : 'draft', 'version' => $drama->version + 1, 'access_version' => $drama->access_version + 1]);
            $plan = $drama->plan;
            $changes = ['version' => $plan->version + 1, 'start_number' => $this->nextNumber($drama), 'next_at' => $next,
                'state' => $data['choice'] === 'restore' ? 'active' : 'draft', 'last_error' => null];
            if ($data['choice'] === 'reset') {
                $changes += ['mode' => 'completed', 'interval_days' => 1, 'batch_size' => 10, 'launch_at' => now()->setTimezone('Asia/Shanghai')->addDay()->startOfDay()->utc()];
            }
            if ($this->completed($drama)) {
                $changes['state'] = 'finished';
                $changes['next_at'] = null;
            }
            $plan->update($changes);
            ContentGuard::changed('drama.relisted', 'content_drama', $id, $drama->version);

            return $drama;
        });
    }

    public function execute(string $planId, int $version, CarbonImmutable $scheduledAt): string
    {
        return ContentGuard::mutate(function () use ($planId, $version, $scheduledAt) {
            $plan = Plan::findOrFail($planId);
            if (Publication::where('plan_id', $planId)->where('plan_version', $version)->where('scheduled_at', $scheduledAt)->exists()) {
                return 'duplicate';
            }
            if ($plan->version !== $version || $plan->state !== 'active' || ! $plan->next_at?->equalTo($scheduledAt) || $scheduledAt > now()) {
                return 'obsolete';
            }
            $drama = $plan->drama;
            if ($drama->state === 'withdrawn') {
                return 'obsolete';
            }
            // D03 is explicitly unresolved. Do not invent a grace period or catch-up policy.
            if ($scheduledAt < now()->startOfMinute()) {
                $plan->update(['state' => 'missed_pending_policy', 'last_error' => 'D03_DECISION_REQUIRED', 'next_at' => null, 'version' => $plan->version + 1]);
                $this->record($plan, $version, $scheduledAt, 'held', [], 'D03_DECISION_REQUIRED');

                return 'held';
            }
            [$from, $through] = $this->range($drama, $plan);
            $reason = $this->blockReason($drama, $from, $through, now());
            if ($reason) {
                $this->record($plan, $version, $scheduledAt, 'blocked', [], $reason);
                $plan->update(['next_at' => $scheduledAt->addDays($plan->interval_days), 'last_error' => $reason]);

                return 'blocked';
            }
            $numbers = $this->publish($drama, $from, $through);
            $done = $this->completed($drama);
            $this->record($plan, $version, $scheduledAt, 'published', $numbers);
            $plan->update(['state' => $done ? 'finished' : 'active', 'next_at' => $done ? null : $scheduledAt->addDays($plan->interval_days),
                'start_number' => $this->nextNumber($drama), 'last_error' => null]);

            return 'published';
        });
    }

    private function record(Plan $plan, int $version, CarbonImmutable $scheduledAt, string $result, array $numbers, ?string $error = null): void
    {
        Publication::create(['drama_id' => $plan->drama_id, 'plan_id' => $plan->id, 'plan_version' => $version,
            'scheduled_at' => $scheduledAt, 'executed_at' => now(), 'source' => 'scheduled', 'result' => $result, 'episode_numbers' => $numbers, 'error_code' => $error]);
    }

    private function range(DramaRecord $drama, Plan $plan, ?int $manualThrough = null): array
    {
        $from = $this->nextNumber($drama);
        $through = $plan->mode === 'completed' ? ($drama->total_episodes ?? 0) : ($manualThrough ?? ($from + $plan->batch_size - 1));
        if ($manualThrough !== null && $plan->mode === 'completed' && $manualThrough !== $drama->total_episodes) {
            return [$from, 0];
        }
        if ($drama->total_episodes) {
            $through = min($through, $drama->total_episodes);
        }

        return [$from, $through];
    }

    public function nextNumber(DramaRecord $drama): int
    {
        $numbers = $drama->episodes()->whereNotNull('published_at')->pluck('number')->all();
        $next = 1;
        foreach ($numbers as $number) {
            if ($number !== $next) {
                break;
            } $next++;
        }

        return $next;
    }

    public function completed(DramaRecord $drama): bool
    {
        return $drama->total_episodes !== null && $this->nextNumber($drama) === $drama->total_episodes + 1;
    }

    public function blockReason(DramaRecord $drama, int $from, int $through, CarbonImmutable $at): ?string
    {
        if (! trim($drama->title_en.$drama->title_zh)) {
            return 'TITLE_REQUIRED';
        }
        if (! $drama->cover || $drama->cover->state !== 'ready') {
            return 'COVER_NOT_READY';
        }
        if (! $drama->regions || ! $drama->license_from || ! $drama->license_until || $at < $drama->license_from || $at >= $drama->license_until || ! trim($drama->rights_note)) {
            return 'LICENSE_RECORD_REQUIRED_OR_EXPIRED';
        }
        if ($through < $from || $through < 1) {
            return 'TOTAL_OR_RANGE_INVALID';
        }
        $episodes = $drama->episodes()->with(['video', 'subtitle'])->whereBetween('number', [$from, $through])->get();
        if ($episodes->count() !== $through - $from + 1) {
            return 'EPISODE_GAP';
        }
        foreach ($episodes as $ep) {
            if (! $ep->video || $ep->video->state !== 'ready') {
                return 'MEDIA_NOT_READY';
            }
            if ($ep->subtitle_asset_id && (! $ep->subtitle || $ep->subtitle->state !== 'ready')) {
                return 'SUBTITLE_NOT_READY';
            }
            if ($ep->subtitle && ! is_file(app(MediaStore::class)->path($ep->subtitle->output_key.'/subtitles.vtt'))) {
                return 'SUBTITLE_NOT_READY';
            }
            if ($ep->subtitle && ($ep->subtitle->metadata['last_end'] ?? INF) > ($ep->video->metadata['duration'] ?? 0) + 0.5) {
                return 'SUBTITLE_OUT_OF_RANGE';
            }
            if (! app(MediaStore::class)->playable($ep->video)) {
                return 'MEDIA_FILE_MISSING';
            }
        }

        return null;
    }

    private function publish(DramaRecord $drama, int $from, int $through): array
    {
        $numbers = range($from, $through);
        $drama->episodes()->whereBetween('number', [$from, $through])->whereNull('published_at')->update(['published_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        $drama->update(['state' => 'published', 'version' => $drama->version + 1]);
        ContentGuard::changed('published', 'content_drama', $drama->id, $drama->version);

        return $numbers;
    }

    private function future(string $value): CarbonImmutable
    {
        $next = CarbonImmutable::parse($value)->utc();
        if ($next <= now() || $next->second !== 0) {
            throw ValidationException::withMessages(['next_at' => '请明确确认未来的北京时间，精确到分钟']);
        }

        return $next;
    }
}
