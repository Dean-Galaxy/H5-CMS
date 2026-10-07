# 03-001 Billing 接入与待决策规则

2026-10-07；基线 h5-content-v1 = 41dbd990e5cd55a75c38141d25c7bd32e6018862；共同契约 1.0.2。

用户已在 03 任务明确启动开发并要求向 00 早交首购切片。基础门槛 A 已在台账通过；03 独立工作区 `/Users/hejunxiang/.codex/worktrees/h5-03-billing/H5`，分支 codex/03-billing，网站 8203 / PG 5603。

## 请求 00 处理的共同接入

1. 将 `App\Modules\Billing\BillingServiceProvider` 加入 local_module_providers 的 billing 允许列表，让 local/testing 可用 `H5_LOCAL_MODULES=billing`；正式接入仍由 00 独立接收后变更 modules provider/ready。不改变 DTO 或 1.0.2 语义；未接入仍 503。
2. Cashier 已锁定且自动发现关闭，保留唯一 `/webhooks/stripe`。03 在模块内使用锁定 Cashier 的 Stripe 客户端基础和相同 API 版本，不启用其默认状态机、路由或 Identity Billable/支付列。账务只维护 billing_* 表，以可信 Identity UUID 关联，不添加跨模块外键。
3. 03 模块内部配置保持模式默认 unconfigured，仅允许明确的 stripe_test 配置；没有正式收款模式。测试桩仅 testing 注入；local 普通浏览器不提供假付款按钮。无 Stripe 凭据时真实 checkout 显式 503。

## D02 未付款保价（提案，未冻结）

总台账仍写未定，F01 的 5 分钟仅用于恢复确认。建议 Offer 快照 30 分钟内允许建单，已创建 Stripe Checkout 使用从创建起 30 分钟的原价快照；所有成功付款仍按订单快照校验。过期后必须重取 Offer 明示价格；旧会话仍在 processing/未知时先对账，不能释放账号占位再创建另一订阅。Stripe Checkout expires_at 接受创建后 30 分钟到 24 小时，因此不直接设置 5 分钟。若 00 希望从 Offer 签发起总计 30 分钟，则建单时剩余不足 30 分钟需重新确认/签发，不能静默延长。

在 00 冻结前，生产/官方 checkout 不开启；隔离测试显式提供测试期限，不冒充产品决定。

## D04 退款（提案，未冻结）

已确认的实际全额退款照对应付款撤销权益并停止关联续费，其他付款区间保留。部分退款只保存平台已成功退款金额并标记待核对，不猜测折算天数、不自动撤销全部权益。法定异常退款入口由 00 集中确认；不建普通退款审批流，客服无退款/加时/恢复权限。

## 接口与验证

消费者为 02/04 的 MembershipReader、04 的 BillingService、05 的 PaymentSettled.v1。首购付款与权益、事件 outbox 原子提交；订阅占位和优惠成功使用由数据库锁及唯一约束保障。03 提交异常用例、真实 PG 并发、迁移、契约及依赖审计。官方账户未配置、未验证；个人账户及旧密钥不使用。请求 00 发布兼容补丁及 D02/D04 决定，03 继续可独立推进的实现。
