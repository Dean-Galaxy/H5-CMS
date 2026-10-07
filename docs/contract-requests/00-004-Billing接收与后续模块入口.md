# 00-004 Billing 接收与后续模块开发入口

2026-10-07，00 已接收。共同契约保持 1.0.2，完整版本与验证见 [00-08](../handoffs/00-08-03修复接收与后续开工.md)。

- 变更：已验证的 BillingServiceProvider 默认装载，BillingService 和 MembershipReader 使用真实 BillingEngine；`/health` 的 billing 为 ready。该值只表示本地模块接入，Stripe 渠道仍默认 unconfigured。
- 兼容性：没有更改 OpenAPI 字段、PHP 方法签名、DTO、金额/时间规则或 D02/D04；Health 的 ready/not_ready 已由 1.0.2 定义，因此无需再发布新数据契约版本。旧标签保持不动，新共同起点 h5-billing-v1。
- 开发入口：受限制的 local/testing 允许列表新增 audience、acquisition，分别对应其模块名下的 ServiceProvider。默认正式配置仍为未接入；缺类、非本地环境、任意类名和替换已接收提供方继续拒绝。
- 消费者：02/04 读取真实会员，04 读取真实账务；05 继续通过稳定 PaymentSettled 事实接收付款。05 未接入时归因为空，outbox 保留待投递，不妨碍付款入账。
- 归属：00 修改共同配置和 Contracts 测试；01、02 分别提交自己的缺依赖测试适配，保留原断言；03 的修复源码保持原交付内容。
- 验证：最终组合 173 项/1596 断言通过；47 个样例及真实本机 HTTP、迁移、依赖审计通过。官方 Stripe、04/05 实现和外部环境未验。

D05 来源规则、04/05 页面插槽及外部测试部署环境仍按申请/接收流程统一，不由这一技术入口自动决定。
