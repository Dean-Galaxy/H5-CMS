<?php

namespace App\Modules\Identity\Services;

use App\Contracts\IdentityContext;
use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Owner-only presentation of existing audit facts; never writes or infers historical actors. */
final class StaffAudit
{
    private const ACTIONS = [
        'login' => '登录', 'login.failed' => '登录失败', 'logout' => '退出登录',
        'staff.created' => '新增员工', 'staff.updated' => '修改员工权限或状态',
        'session.revoked' => '撤销登录会话', 'permission.denied' => '权限检查',
        'password.reset' => '重设观众密码', 'verification.sent' => '发送邮箱验证邮件',
        'email.verified' => '验证邮箱', 'email.corrected' => '修改邮箱',
        'content.drama.saved' => '保存剧目资料', 'content.episode.saved' => '保存分集资料',
        'content.episode.media_replaced' => '替换分集素材', 'content.episode.deleted' => '删除分集',
        'content.free.saved' => '修改单剧免费范围', 'content.free.default' => '修改默认免费范围',
        'content.category.saved' => '保存分类', 'content.banner.saved' => '保存 Banner',
        'content.upload.completed' => '完成素材上传', 'content.upload.canceled' => '取消素材上传',
        'content.plan.launched_now' => '立即启动发布', 'content.plan.batch_published_early' => '提前发布下一批',
        'content.plan.saved' => '保存发布计划', 'content.plan.activated' => '启用发布计划',
        'content.plan.paused' => '暂停发布计划', 'content.plan.resumed' => '恢复发布计划',
        'content.drama.withdrawn' => '下架剧目', 'content.drama.relisted' => '重新上架剧目',
        'content.published' => '发布分集',
        'billing.price.created' => '保存会员价格', 'billing.case.opened' => '创建客服记录',
        'billing.case.viewed' => '查看客服记录', 'billing.case.verified' => '核实客服记录',
        'billing.case.verification_cleared' => '撤销客服核实', 'billing.case.note_added' => '添加客服备注',
        'billing.case.access_denied' => '客服记录权限检查', 'billing.support.cancel' => '客服代取消续费',
        'billing.support.cancel_requested' => '提交客服代取消请求',
        'billing.cancel' => '取消续费', 'billing.resume' => '恢复续费',
        'acquisition.pixel.created' => '登记像素', 'acquisition.pixel.updated' => '修改像素',
        'acquisition.pixel.assigned' => '分配像素', 'acquisition.link.created' => '新增投流链接',
        'acquisition.link.updated' => '修改投流链接', 'acquisition.link.assigned' => '分配投流链接',
        'acquisition.pixel.local_probe' => '本地模拟像素检查',
        'acquisition.delivery.retry_requested' => '提交发送重试请求',
    ];

    public function recent(): Collection
    {
        app(IdentityContext::class)->requireStaff(Permission::StaffManage);

        // Join only Identity-owned staff, including disabled accounts. Do not hydrate credentials
        // or expose contact information; a viewer ID must never resolve to an employee name.
        return DB::table('identity_audit_events as audit')
            ->leftJoin('identity_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'audit.actor_id')->where('audit.actor_kind', 'staff');
            })
            ->orderByDesc('audit.occurred_at')->orderByDesc('audit.id')->limit(30)
            ->get(['audit.id', 'audit.occurred_at', 'audit.action', 'audit.result', 'audit.actor_kind',
                'audit.actor_id', 'audit.request_id', 'audit.changes', 'staff.name as actor_name'])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'time' => CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d H:i'),
                'action' => self::ACTIONS[$event->action] ?? '其他操作（未识别类型）',
                'result' => $this->result($event),
                'actor' => $this->actor($event),
                'request_id' => $event->request_id,
            ]);
    }

    private function result(object $event): string
    {
        if ($event->action === 'session.revoked' && $event->result === 'denied') {
            return '旧会话已失效';
        }

        if ($event->action === 'verification.sent' && $event->result === 'success') {
            $changes = json_decode($event->changes, true);

            return in_array($changes['mail_mode'] ?? null, ['log', 'array'], true)
                ? '已生成本地预览' : '已提交发送（送达未确认）';
        }

        return match ($event->result) {
            'success' => '成功', 'denied' => '已拒绝', 'confirmed' => '已确认',
            'accepted' => '请求已受理', 'simulated' => '仅本地模拟',
            'failed' => '失败', 'pending' => '处理中',
            default => '结果未识别',
        };
    }

    private function actor(object $event): string
    {
        if ($event->actor_kind === 'staff' && $event->actor_id !== null) {
            $name = trim($event->actor_name ?? '');
            $label = $name === '' ? '员工（账号不可用）' : mb_substr($name, 0, 1).'＊＊';

            // Short suffix distinguishes masked names without exposing the complete account ID.
            return $label.' · '.substr($event->actor_id, -6);
        }
        if ($event->actor_kind === 'viewer' && $event->actor_id !== null) {
            return '观众（已脱敏）';
        }

        // Recorder uses system for both background work and unauthenticated attempts.
        // It does not prove a specific human or an automated job performed the action.
        return '未记录操作者';
    }
}
