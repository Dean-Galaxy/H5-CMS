# 04 / 05 浏览器采集对接说明与待确认边界

基线 32e878f；附加 analytics-v1，业务契约 1.0.5 不变。04 不修改公共定义。

04 消费 context/consent/events 三个已冻结 API；浏览器 properties 仅提交 `path`（无 query）、`drama_id/episode_id`（页面中的不透明 UUID，仍需 05 验证公开关系）、整数 `position_seconds/duration_seconds/watched_seconds/startup_ms/stall_ms` 和短枚举 `error_code`。不提交 viewer/source/order/payment/金额/国家/URL。服务器只用公共 BehaviorEventRecorder，原单稳定 key。

请 00/05 接收时确认以上逐事件白名单。当前公共 Catalog 只有事件名，没有逐事件 property schema；04 实现会在发送前筛掉未知字段。watch_progress 的 watched_seconds 为该播放会话累计有效媒体前进秒数，seek 不加；episode_completed 要求到达结束且实际累计至少 90% 时长，保守排除拖到结尾。需要不同统计口径时应由 00 明确后同步。

已解决：00 c9a1d9b 新增 POST /api/v1/tracking/landing；04 在 advertising granted、pending_landing 且详情页真正可见时只提交 page_instance_id/drama_id/path。analytics denied 时不发通用行为事件。通用实例字段已由 ecf132b 冻结；错误枚举及数值上限与05 EventProperties实现对齐。

04 partial 仅提供浏览器本地缓冲隔离用的会话 HMAC（session id + 当前观众、应用 key），不发送到 events API、不作为 actor/source、不授予任何权限。登录会话再生或换号后旧缓冲丢弃；退出表单提交前也清空并广播。05 的 clearBrowserContext 仍负责服务器退出/换号边界。
