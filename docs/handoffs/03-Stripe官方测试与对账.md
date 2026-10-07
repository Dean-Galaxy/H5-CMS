# 03 Stripe 官方测试与对账手册

2026-10-07。本手册是待执行方案及本地验证边界，**不是 Stripe 官方测试通过报告**。公司对应账号未准备；未使用个人账号、旧密钥、正式资产或真实收费。所有官方测试结果目前均为「未执行／未验证」。

## 固定实现及版本

- 锁定依赖 Cashier 16.8.0、stripe-php 21.3.2；依赖文件未修改。使用 Cashier 的客户端与固定 API 版本，付款和权益由 Billing 自有状态机管理；未启用 Cashier 默认路由或第二套 Identity Billable 状态。
- API 固定 `2026-08-26.dahlia`。这是本项目兼容版本，不宣称是最新；请求及 webhook 端点必须匹配。签名通知的版本不匹配会拒绝。[锁定 SDK 的版本源码](https://github.com/stripe/stripe-php/blob/v21.3.2/lib/Util/ApiVersion.php)、[Stripe 版本规则](https://docs.stripe.com/api/versioning)。
- 托管 Checkout：subscription 模式、card、USD、quantity=1、week×1、不自动加税、不放开用户折扣码；首次优惠用本订单专属一次性减免，续费以原订单常规价创建的 Price 为准。无站内保存卡号、付款凭据或持久媒体地址。
- D02：本人 Offer 有效 30 分钟；远端客户/优惠准备好后保存完整创建参数、幂等键和准备时间，固定截止 = 准备时间 +1860 秒。平台要求创建后最短 30 分钟、最长 24 小时；本项目 60 秒是通信余量。[Checkout 创建参数](https://docs.stripe.com/api/checkout/sessions/create)。重试不重算截止、不换幂等键；未知先查原 Customer 下对应订单 Session。

## 账号准备后由谁做什么

1. 用户持有项目对应 Stripe 测试账号或 Sandbox，并确认可在其内测试周订阅。不要把密钥、签名秘密或密码发到聊天、交付单、源码、截图或日志。
2. 在独立 local/testing 工作区的私有 `.env` 安全填写 `H5_BILLING_DRIVER=stripe_test`、`H5_BILLING_STRIPE_TEST_SECRET`、`H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET`。保留模块配置 `H5_LOCAL_MODULES=billing` 和本机美国地区 `H5_LOCAL_US_TEST_REGION=true`。secret 只接受测试模式；默认 unconfigured，没有正式收款 driver。
3. 用官方受控测试 webhook/CLI 转发到本工作区 `/webhooks/stripe`，核对 API 版本。转发工具临时签名 secret 与 Dashboard 端点 secret 不混用。公网 HTTPS 测试站及其入口由 00/06安排，本地端口不能当公网可访问证明。
4. 配置后停止再启动本工作区，刷新常驻 worker/scheduler。负责人在后台明确保存测试价格，不从其他工作区复制数据或配置。用全新已验证测试观众完成 Checkout；04 页面接好后从正常网站按钮进入。
5. 每个用例保留环境/Sandbox 标识、代码提交、UTC 时间、站内订单/付款/订阅 ID、对应 `cs_`/`in_`/`sub_`/`evt_` 测试对象引用、期望与实际金额/周期、通知投递和对账结果。去除凭据、签名、姓名邮箱与银行卡信息。测试对象引用放受控证据，不发布生产日志。

## 官方周期验证办法

优先新测试观众、新 Customer、新 Test Clock，初始时钟接近当前 UTC。可选 `.env` 中 `H5_BILLING_TEST_CLOCK_ID` 指向官方已创建的测试时钟；新 Customer 创建传入该 ID，既有 Customer 必须本来就绑定同一时钟，不自动改绑。默认不配置时钟。

官方 Customer API 支持 `test_clock`，订阅继承 Customer 时钟，Checkout 支持已有 Customer；本适配器按这些接口组合。**已验证的只是本地参数与拒绝逻辑，尚未实际验证公司 Sandbox 中 Checkout 与 Test Clock 的组合。** [Customer 创建](https://docs.stripe.com/api/customers/create)、[时钟 API 用法](https://docs.stripe.com/billing/testing/test-clocks/api-advanced-usage)、[Checkout Customer 参数](https://docs.stripe.com/api/checkout/sessions/create)。

如该账号或流程不支持预绑定组合，按官方 Dashboard 测试订阅详情的 **Run simulation → Advance time** 路径验证已建立的测试订阅，并保存该平台测试能力实际可用的证据；若无此入口则继续保持周期官方验证未完成，不能改本地系统时间冒充。[官方模拟操作](https://docs.stripe.com/billing/testing/test-clocks/simulate-subscriptions)。

每次推进不超过官方允许的两个最短周期，等待时钟回到 ready；续费发票可能先 draft，再推进约一小时完成最终化。按 Customer/Subscription/Clock 过滤取对象，避免把列表遗漏当作没有付款。官方当前限制包括每时钟最多 3 个 Customer、每客户 3 份订阅。Finish simulation 会删除相应测试对象，因此先保存脱敏证据，并由账号持有人决定是否清理。[限制和推进说明](https://docs.stripe.com/billing/testing/test-clocks/api-advanced-usage)。

时间推进须由 Stripe 官方 Test Clocks 产生真实测试发票和通知。本地单元测试的 `travelTo` 仅验证权益边界；真实网站时钟不被修改，不能据本地时间变化声称官方续费通过。

## 待执行的官方用例与当前结果

| 用例 | 必须观察到的结果 | 当前结果 |
|---|---|---|
| 首购/关闭支付页/再次进入 | 只有核实成功付款才消耗首惠；原进行中 Session 复用 | 未执行 |
| 两次同时点击/创建超时 | 单一订单和订阅占位；固定创建参数；晚到结果归原单 | 未执行 |
| 成功付款及验证金额/归属 | 站内原订单金额、USD、Customer、Subscription、实际支付及 Invoice line 周期一致 | 未执行 |
| Test Clock 续费 | 新 invoice 对应原常规价和实际下一周期；一笔付款、一段权益、一条出站事件 | 未执行 |
| 续费失败/需要认证/补款 | 不增加未付权益；待核对时阻止新购；补款只补 invoice 原周期 | 未执行 |
| 取消/调用超时/恢复 | 到期取消；成功后本期保留；F01 显示原价/下次时间并确认，不立即扣款或改锚点 | 未执行 |
| 调价/结束后再订 | 原持续/可恢复订阅保留原价；已结束新订采用当前常规价，不再首惠 | 未执行 |
| 部分/累计全额退款/失败更正 | 成功部分退款保留原区间并停续费；全退只撤对应权益；更正只恢复原区间 | 未执行 |
| 延迟/重复/乱序/错签名 | 事件持久落地，当前平台事实优先；不重复付款、权益、首惠 | 未执行 |
| 消息消费者故障/补发 | 付款已入账；outbox 待发送并可恢复，不因05故障回滚付款 | 本地通过，官方付款组合未执行 |

普通成功可用官方 `4242424242424242` 测试卡；美国 3DS 测试按官方当前表格选择，例如 `4000008400000027`，始终认证卡 `4000002760003184`。仅在测试模式填写这些官方测试卡；SCA 必须从本站 Checkout/API 流程触发，Dashboard 直接建付款不能替代交互验证。拒付和 off-session 失败场景同样按官方测试说明选择，不使用真实卡。[测试卡与认证](https://docs.stripe.com/testing#regulatory-cards)。

## API 及事件映射

| Stripe 输入 | 本地处理 |
|---|---|
| `checkout.session.completed`、`async_payment_succeeded`、`async_payment_failed`、`expired` 等 `checkout.session.*` | 以 Session ID 定位原订单，再读取平台 Session/订阅/发票；浏览器跳转和 completed 本身不发权益 |
| `invoice.paid`、`invoice.payment_succeeded`、`invoice.payment_failed`、`invoice.payment_action_required`、`invoice.updated` 等 `invoice.*` | 重新读取 invoice、InvoicePayment、PaymentIntent、Charge；只接受真实已成功测试付款及精确金额/归属/币种/周期，失败或需操作只更新状态 |
| `customer.subscription.created/updated/deleted` 等 | 读取当前 subscription 的 items 周期、取消标记、status 和 latest_invoice，必要时核对付款；active 本身不是已付权益 |
| `charge.refunded`、`refund.created/updated/failed` 等 | 以 charge 查全部分页退款对象，按唯一退款对象当前状态重算 succeeded 总额，执行 D04 |
| 其他已签名且匹配版本的事件 | 标为 ignored，不生成付款事实 |

订阅周期按当前接口 `items.data[].current_period_start/end`，权益以 invoice line `period` 为准。付款链核对 InvoicePayment → PaymentIntent → Charge；手工标 paid 或信用余额不能伪装成已验证收款。[Subscription](https://docs.stripe.com/api/subscriptions/object)、[Invoice line](https://docs.stripe.com/api/invoice-line-item/object)、[InvoicePayment](https://docs.stripe.com/api/invoice-payment/object)。

通知以原始 body 验签并检查 test/live/API version，inbox 仅保存必要对象引用，不保存原始付款报文。事件无顺序保证，可重复，平台重试与本站重试均需幂等。Dashboard/CLI 重发支持窗口以官方当前说明为准。[Webhook](https://docs.stripe.com/webhooks)。

F01 使用订阅更新 `cancel_at_period_end=false`，不使用 `/subscriptions/:id/resume`（后者用于 paused）。停止续费用 true，不立即取消本期付款。[取消与恢复](https://docs.stripe.com/billing/subscriptions/cancel)、[resume API](https://docs.stripe.com/api/subscriptions/resume)。退款状态可能 pending、failed、canceled；`charge.refunded` 也可能是部分退款。[退款](https://docs.stripe.com/refunds)。

## 对账和补偿

已注册每分钟 `billing:reconcile`，独立 scheduler 必须运行。需要人工立即核对时在本工作区执行：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-03-billing/H5
/opt/homebrew/opt/php@8.4/bin/php h5-platform/artisan billing:reconcile --limit=100
```

处理 inbox 待办/过期处理中项、持久化建单和取消恢复操作、待定 Checkout、全部轮转订阅与发票、付款关联退款，再发 outbox。各类游标轮转，单项失败不阻断后续；命令输出安全计数，细项仅保存固定错误码。命令成功只说明本轮完成，不证明不存在未配置、无消费者或未知创建意图，仍需查看对应记录。

- 客服用站内订单号打开账务记录，查看 payment_state、已退金额、会员有效区间、续费及待核对标志；不能直接更改权益或退款。
- 付款、对应权益和 `PaymentSettled.v1` 在同一事务落地；事件区分 first_subscription、resubscription、renewal 和 is_test。没有 05 消费者则 `CONSUMER_NOT_READY` 待发送。消费者必须按 event_id 幂等，可能收到至少一次投递；不得在回调中直接同步等待广告网络。
- 未知的 Checkout 创建只查询原 Customer 下对应订单。发现唯一 Session 才复用；未找到并不足以证明没有付款，不自动释放占位。若进程在「已标记尝试」与真正远端 POST 之间退出，可能进入需要人工平台核实的待定状态；首版没有强制释放/伪造成功按钮。账号准备后的官方故障演练需确认这个处置流程。
- 实际退款由账号持有人在受控 Stripe 入口依法按实际情况办理，本站只同步；不恢复普通退款审批流，不宣称所有款项均不可退款。最新平台事实可能修正退款总额，但不恢复续费或首次优惠。
- 客服核验在账号锁之后重新读取并锁定，一次核验仅受理一次取消；受理即消费，并先留审计。平台未知由原 operation 重试。旧客服页面、旧 F01 或旧退款重试均不得覆盖用户之后的新决定。
- 不能用 SQL 手工将订单标已付、延长权益、清待核对或复位首惠。发现数据矛盾交 00/03检查，对照平台事实后通过受审查的补偿路径处理。
