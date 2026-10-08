# 共同契约 v1

当前版本：`1.0.4`，共同起点 `h5-shared-v1.0.4`，详见[00-17接收记录](../handoffs/00-17-公共运行环境补丁与06阶段接收.md)。1.0.3已加入投流只读依赖；本次增加[公共运行环境检查](controlled-staging-v1.0.4.md)，既有HTTP路径、DTO及业务接口签名未变。staging支付、可信地区、投流来源及外部部署仍未放行。旧基础/Content/Audience/Acquisition标签保留原提交。OpenAPI使用YAML 1.2兼容的JSON表达，金额样例仅供测试。

[恢复续费、首页与分类的完整约定](shared-v1.md) 定义 F01 的先读确认信息再提交，以及 F02 的语言、排序、分类筛选和下架过滤。PHP 类型、HTTP、样例和消费测试一起发布。

[账务实施约定 v1](billing-lifecycle-v1.md) 定义 D02 的双窗口、固定支付创建参数，以及 D04 的部分退款、累计全退和停止关联续费。由 00 依据用户在 03 启动时的委托发布，字段和方法不变，契约仍为 1.0.2；功能及官方支付未验。[00-06](../handoffs/00-06-Billing开发接入补丁.md) 只允许 Billing 在自己的 local/testing 环境显式装载，主工程仍默认未接入，不能把允许列表理解为功能验收。

## 实现边界

| 提供方 | 本次交付 | 后续负责 |
|---|---|---|
| 01 | IdentityContext、AuditRecorder、观众身份、独立后台及员工权限 | 继续维护 Identity |
| 02 | 真实 CatalogReader、PlaybackAuthorizer、内容后台、私有媒体与正常发布已接入 | D03 故障漏排期、03 真实会员、04 真机与外部媒体组合验收 |
| 03 | MembershipReader、BillingService、PaymentSettled.v1 类型和未就绪骨架 | 真实报价、订单、支付、订阅、权益及付款事件 |
| 04 | ProgressService 类型和未就绪骨架；网站布局插槽 | 网站、播放器、进度及两种语言完整体验 |
| 05 | AttributionService 类型；关闭时明确无来源 | 推广链接、像素、来源及事件发送 |

普通运行时，缺业务提供方返回 HTTP 503 / `DEPENDENCY_NOT_READY`。这不等于“暂无会员”或“支付失败”。归因关闭是唯一允许的空提供方：返回 `null`，不阻塞未来付款。不能把关闭归因写成校验来源成功。

## 身份和传输

- 站内 ID 为服务端 UUIDv7；不可由请求指定身份、角色或已付状态。内部服务接收由 IdentityContext 创建的身份对象，业务提供方仍检查对象归属。
- 浏览器先 GET `/api/v1/auth/csrf`，保存 cookie，把返回的 `csrf_token` 用 `X-CSRF-TOKEN` 传回；网页表单使用 `_token`。所有状态变更受 CSRF 保护，只有将来的官方 Stripe 原始通知入口豁免。
- 后台 cookie 与观众 cookie 分开；后台 API 只接受通过二次验证的员工会话。未知对象、他人订单等使用 404 或标准权限拒绝，不能泄露其存在。
- 后台 API 使用 `/api/v1/admin/csrf` 的令牌；不能混用观众令牌。后台登录和二次验证由 Filament 页面 `/admin/login` 及 `/admin/livewire/update` 承载，不另造“管理员密码登录即获得权限”的公共 API。
- 正常 JSON 成功响应以各 schema 为准，不额外包 `data`。错误统一 `{error:{code,message,request_id,fields,retryable}}`；`fields` 无字段问题时为 `null`。所有响应带 `X-Request-ID`。
- GET 验证邮件路径需要当前观众会话；跨浏览器打开时先登录本人后继续验证。签名到期、邮箱变更、已消费链接明确失败；只验证不会额外产生会员。
- 返回位置仅允许本站路径白名单，禁止绝对 URL、`//`、反斜杠、控制字符和递归编码逃逸。重定向不决定付款状态。

## 状态及安全责任

- `access_active` 与 `subscription_state` 分开；`canceling` 可仍有有效已付权益；`past_due`、`unknown` 不得直接开始第二份订阅。
- 03 报价对访客的 `eligible_for_first_offer` 为 `null`，付款前重读身份和可信价格；`offer_id` 与 `price_version` 不可替代服务端防篡改检查。
- 金额为最小货币单位整数，币种首版只允许 USD。时间带时区并输出 UTC，权益是 `[access_from, access_until)`。发布业务转换北京时间由 02 负责。
- 播放拒绝时 `grant_id`、`short_lived_url`、`expires_at`、`play_session_id` 必须全为 null；成功也只能包含短期许可地址。
- 配置变更要求 version，比当前旧的版本返回 409 `STALE_VERSION`。订阅恢复的价格及时间确认由 03 校验，不能把请求金额当可信事实。
- ProgressService 查询尚无观看记录时返回 null；保存接口成功则返回完整 Progress。对应业务提供方尚未实现。
- PaymentSettled.v1 是已核实付款事实，不是浏览器成功回跳。03 在业务事务内可靠落地事件，05 按 event_id 去重；`is_test=true` 不得发到正式广告资产。
- 客服取消必须由 03 记录核实意愿依据；客服不能恢复续费、改金额、加权益或导出全量观众。02、03、05 的实际对象越权测试仍归对应提供方。

## 结构和消费样例

执行 `.runtime/contracts/bin/python scripts/validate_contracts.py` 验证 OpenAPI 和 examples/manifest.json 中每个正反例。未具备校验环境时，由开发任务按 `scripts/requirements-contracts.txt` 建独立虚拟环境。

`examples/` 只用于文档和测试；没有运行时自动导入。PHP 消费样例及供应方骨架由共同测试覆盖。更改类型、路由、状态或可空规则必须一起更新契约、例子及测试，并向 00 提交 `docs/contract-requests/` 变更记录。
