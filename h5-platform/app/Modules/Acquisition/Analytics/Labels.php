<?php

namespace App\Modules\Acquisition\Analytics;

final class Labels
{
    private const EVENTS = [
        'page_view' => '页面访问', 'landing_view' => '推广落地', 'drama_view' => '剧目详情', 'entry' => '入口访问',
        'play_requested' => '请求播放', 'play_started' => '实际开始播放', 'watch_progress' => '有效观看进度', 'episode_completed' => '分集播放完成',
        'player_startup' => '首帧等待', 'player_stall' => '播放卡顿', 'player_error' => '播放失败', 'paid_play' => '付款后播放',
        'paywall_view' => '付费门槛展示', 'subscribe_click' => '点击订阅', 'registration_view' => '注册页展示', 'registration_submit' => '提交注册',
        'verification_view' => '邮箱验证页展示', 'checkout_click' => '点击付款', 'payment_return_view' => '付款返回页',
        'registration_succeeded' => '注册成功', 'registration_failed' => '注册失败', 'login_succeeded' => '登录成功', 'login_failed' => '登录失败',
        'email_verified' => '邮箱验证完成', 'logout' => '退出登录', 'checkout_created' => '付款流程已建立', 'checkout_failed' => '付款流程创建失败',
        'payment_pending' => '付款待核对', 'payment_cancelled' => '付款流程已取消', 'payment_failed' => '付款失败', 'payment_confirmed' => '付款已核实',
        'refund_confirmed' => '退款累计额已核实', 'subscription_cancelled' => '未来续费已停止', 'subscription_resumed' => '未来续费已恢复',
        'first_subscription' => '首次订阅', 'resubscription' => '再次订阅', 'renewal' => '自动续费',
    ];

    public static function event(string $name): string
    {
        foreach (['测试：', '真实：', 'test:', 'real:'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return (in_array($prefix, ['test:', '测试：'], true) ? '测试 · ' : '真实 · ').self::event(substr($name, strlen($prefix)));
            }
        }

        return self::EVENTS[$name] ?? '未知/待核对';
    }

    public static function state(?string $code): string
    {
        return [
            'known' => '已核实', 'partial' => '范围不完整', 'unknown' => '未知/待核对', 'disabled' => '未启用',
            'local_simulation' => '仅本地模拟', 'http' => '受控回传（平台未核验）',
            'pending' => '等待发送', 'retry' => '等待重试', 'sending' => '处理中', 'blocked' => '发送已阻断',
            'failed' => '发送失败/次数耗尽', 'api_accepted' => '接口已接受，平台待核验', 'simulated' => '本站模拟通过',
            'unattributed' => '未归属：缺少来源', 'unresolved_source' => '来源待核对', 'matched' => '已匹配冻结来源',
            'test' => '测试', 'production' => '正式',
        ][$code ?? 'unknown'] ?? '未知/待核对';
    }

    public static function error(?string $code): string
    {
        if ($code === null) {
            return '无已记录错误';
        }
        if (str_contains($code, ':')) {
            [$event, $reason] = explode(':', $code, 2);

            return self::event($event).' · '.self::error($reason);
        }

        return [
            'network' => '网络异常', 'media' => '媒体异常', 'region' => '地区限制', 'membership' => '会员资格限制', 'authorization' => '播放授权未通过',
            'timeout' => '等待超时', 'unavailable' => '服务未就绪', 'invalid' => '提交内容无效', 'validation' => '内容校验未通过', 'credentials' => '身份验证未通过',
            'rate_limited' => '请求过于频繁', 'unknown' => '未知/待核对', 'payment_pending' => '付款待核对', 'payment_cancelled' => '付款流程取消', 'payment_failed' => '付款失败',
            'VALIDATION_FAILED' => '内容校验未通过', 'INVALID_CREDENTIALS' => '登录信息未通过验证', 'RATE_LIMITED' => '请求过于频繁', 'AUTHENTICATION_REQUIRED' => '需要登录',
            'NOT_AUTHORIZED' => '无权操作', 'INTERNAL_ERROR' => '服务暂时异常', 'REQUEST_REJECTED' => '请求未通过',
            'CONSENT_REQUIRED_OR_REVOKED' => '未同意广告或已撤回', 'PIXEL_DISABLED' => '像素未启用', 'EXTERNAL_DISABLED' => '广告外发关闭',
            'PAYMENT_ASSET_ENVIRONMENT_MISMATCH' => '付款与资产测试用途不一致', 'SIMULATION_ENVIRONMENT_REQUIRED' => '仅允许隔离测试模拟',
            'EXTERNAL_REVIEW_REQUIRED' => '尚未完成外部回传核对', 'SIMULATED_SOURCE_EXTERNAL_FORBIDDEN' => '模拟来源禁止外发',
            'LOCAL_PRODUCTION_ASSET_FORBIDDEN' => '本地禁止使用正式资产', 'PRODUCTION_TEST_ASSET_FORBIDDEN' => '正式环境禁止测试资产',
            'RETRY_WINDOW_EXPIRED' => '已超过重试时限', 'MATCH_CONTEXT_REQUIRED' => '缺少获准的匹配信息', 'PLATFORM_EVENT_TIME_OUT_OF_RANGE' => '付款事件时间超出回传范围',
            'CHECKOUT_CONTEXT_REQUIRED' => '缺少可信购买页上下文', 'CHECKOUT_CONTEXT_INVALID' => '购买页上下文与冻结来源不符',
            'CHECKOUT_CONTEXT_EXPIRED' => '购买页上下文已过保留期', 'FROZEN_PAYLOAD_NOT_ALLOWED' => '冻结载荷不符合当前最小发送口径',
            'STAGING_TEST_RUNTIME_INVALID' => '受控广告测试环境未通过准入', 'STAGING_TEST_ASSET_REQUIRED' => '受控通道仅允许测试付款与测试资产',
            'STAGING_RENEWAL_NOT_RELEASED' => '受控通道尚未放行续费回传',
            'CREDENTIALS_REQUIRED' => '尚未配置授权信息', 'META_VERSION_OR_ASSET_MISSING' => 'Meta版本或资产未配置', 'TIKTOK_ASSET_INVALID' => 'TikTok资产编号无效',
            'META_RETRYABLE' => 'Meta接口暂时异常，可重试', 'META_REJECTED' => 'Meta接口未接受', 'TIKTOK_RETRYABLE' => 'TikTok接口暂时异常，可重试', 'TIKTOK_REJECTED' => 'TikTok接口未接受',
            'TRANSPORT_UNCERTAIN' => '回传结果待核对', 'DELIVERY_CONTEXT_INVALID' => '回传上下文无效', 'RETRY_EXHAUSTED' => '重试次数耗尽', 'LEASE_LOST' => '本次处理权已失效',
            'click_match_missing' => '仅IP/浏览器匹配，平台归因未核验', 'click_context_rejected' => '广告匹配信息格式未通过', 'landing_confirmation_pending' => '落地确认待完成，可重试',
            'consent_skipped' => '正常未采集：未同意分析', 'event_id_conflict' => '事件编号重传内容冲突', 'browser_envelope_rejected' => '浏览器事件结构不匹配', 'catalog_unavailable' => '目录服务暂时不可用', 'play_reference_conflict' => '播放实例与分集不一致', 'play_order_invalid' => '播放完成顺序不正确', 'http_payload_rejected' => '采集请求超过大小限制', 'http_schema_rejected' => '采集请求结构不匹配', 'http_rate_limited' => '采集请求达到限流', 'http_origin_rejected' => '采集请求来自其他网站',
            'capture_failed' => '采集存储暂时失败', 'invalid_server_event' => '服务端事件校验未通过', 'invalid_browser_event' => '浏览器事件校验未通过',
            'invalid_browser_reference' => '剧集引用未通过校验', 'consent_failed' => '隐私选择保存失败', 'context_clear_failed' => '身份上下文清理待核对',
        ][$code] ?? '未知/待核对';
    }
}
