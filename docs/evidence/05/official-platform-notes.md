# 05 平台适配依据与边界

查阅日期：2026-10-07。仅依据两家官方文档和官方仓库；没有使用投流账号、真实令牌或真实平台发送结果。

## Meta

- 官方 SDK 的 [Event](https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/Object/ServerSide/Event.php)、[UserData](https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/Object/ServerSide/UserData.php) 与 [EventRequest](https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/Object/ServerSide/EventRequest.php) 定义事件时间、事件 ID、来源、匹配字段、批量 data 和测试事件代码。05 发送 Purchase；只映射 IP、UA、已获许可的 fbc/fbp，不取用户邮箱、电话、订单明细。
- 请求发往固定 Graph API `/版本/像素编号/events`，令牌放 Authorization 头，不在 URL/日志中。原始付款时间转换为 Unix 秒；金额从 USD 整数分转换一次。续费 action_source 为 system_generated，另带 transaction_kind，外部适用性仍需账号联调核实。
- [官方 ApiConfig](https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/ApiConfig.php) 查阅时为 v26.0；本地请求断言显式使用 v26.0。运行默认版本留空，不自动猜平台版本。Meta 技术文档页面本次曾返回 429，字段核对改用上述官方源码；不能据此声称已完成账户/API 准入检查。
- 只有 HTTP 成功、`events_received=1` 且没有 error 才记 `api_accepted`；不称平台归因成功。429、5xx、明确 transient 错误进入重试，其他拒绝记失败。

## TikTok

- [Standard Events and Parameters](https://ads.tiktok.com/resources/help/article/standard-events-parameters?lang=en) 页面更新于 2026-04，购买事件使用 Purchase。05 没有沿用旧 CompletePayment 名称。
- [Events API 2.0 官方参考](https://business-api.tiktok.com/portal/docs?id=1771100779668482) 与 [官方 GTM Events API 模板](https://github.com/tiktok/gtm-template-eapi/blob/main/template.tpl) 用于核对固定 `/open_api/v1.3/event/track/`、Access-Token、event_source、event_source_id、data、Unix 秒时间、event_id、page、user、properties 和 test_event_code。只映射 IP、UA、经许可的 ttclid/ttp，不收集联系方式。
- HTTP 成功且整数 `code=0` 只记 `api_accepted`；429/5xx进入重试，业务错误进入失败，保存脱敏原因码。HTTP 200 带错误码和跳转响应不能冒作成功；不跟随跳转传递令牌。
- [Event Deduplication](https://ads.tiktok.com/resources/help/article/event-deduplication?lang=en) 说明相同事件和 event_id 的去重窗口为 48 小时。05 按同一付款构造稳定事件 ID，并在网络 I/O 前记录首次尝试；跨进程恢复也保留该时间。当前工程使用更保守的 24 小时自动/人工重排截止，超过截止阻断，不能换新 ID 盲目重发。这是发送重试保护，不是 D05 来源窗口。

## 本次验证能说明什么

| 层级 | 本次结果 |
|---|---|
| 适配实现 | 两家分别转换与发送；白名单字段、固定 HTTPS 端点、12 秒请求超时、4 秒连接超时、禁止重定向 |
| 本地发送器 | 明确 local_simulation，使用真实适配报文计算摘要与去重，不访问网络；后台两家测试资产均已实际点击验证 |
| HTTP 分支 | Laravel HTTP 测试替身检查真实组装请求、响应分类和原报文重试；全局阻止未声明外部请求 |
| 真实 HTTP 接受 | 未验证；没有向 Meta/TikTok 发请求 |
| 平台确认/匹配/归因 | 未验证；必须由账号持有人在测试事件工具核对，不能由本地测试推断 |

发送前还要求：支付测试标记与资产环境一致；来源有明确隐私同意及政策版本、未撤回；像素仍启用；外发与平台复核开关明确开启；来源不是模拟；匹配上下文为本站 HTTPS 无查询串页面、合法公开 IP 和 UA；原付款时间未超过本地保守 7 天发送截止。该 7 天是传输保护，不确认第一方来源窗口或平台归因窗口。

当前 D05 未冻结，没有普通运行的来源写入入口。默认 transport=disabled、external_enabled=false、platform_reviewed=false；不能只改这三个配置就宣称具备外部准入条件。政策/同意如何产生、撤回如何衔接、浏览器脚本插槽、平台用户数据要求和正式资产授权，仍由 00 与 01/04、账号持有人统一落实。
