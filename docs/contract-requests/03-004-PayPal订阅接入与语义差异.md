# 03-004 PayPal Subscriptions 接入申请（待 00 接收）

2026-10-26；现行业务契约 **1.0.5**。本文件是候选实现方案，不改冻结契约，不代表官方支付通过。依据是用户在 H5 00 的新增授权：Stripe 仍在申请，先接 PayPal，保留现有稳定验收环境。下午误派中的中国内地主体信息不属于 H5；本方案保持 H5 原美国市场、USD、周订阅规则，商户主体/地区/企业类型待本人提供。

## 固定起点与当前事实

- 工作区 `/Users/hejunxiang/.codex/worktrees/h5-03-billing/H5`，分支 `codex/03-billing`。从干净 `7895abc1ff8a2650ef767356dada0da033a9a015` 正常快进指定主线 **`78cb6644004c017a2a04ac791025372f020b0023`**，没有 reset 或复制配置。此前相差 86 提交，Billing 源码无差异。
- 周四应用 **`6b6e7d5149b2e4b599766e22d73ff99e65c159d5`**；原标签 `h5-content-v1^{commit}` 核为 `41dbd990e5cd55a75c38141d25c7bd32e6018862`。基础门槛 A 与旧模块已接收；旧标签不动。
- 00 本次通报 07 本机人工 12/12 完成；这不是 PayPal/Stripe 官方支付验收。本轮不操作 8303/8377/8378 或其他工作区服务、账号、素材、数据库。
- 当前 `BillingServiceProvider` 只绑定 `stripe_test` / `UnconfiguredGateway`；Cashier 只用于构造 Stripe SDK，自动发现关闭，未启用另一份 Billable 账本。当前 Laravel 没有 PayPal 订阅适配。旧 Node PayPal 不能作为本模块的代码或官方验证证据。

## 设计决策与待接收差异

| 需求 | 拟实现 / 保持的不变量 | 需冻结或官方补验 |
|---|---|---|
| 每周 USD / 首周优惠 | 服务器价格版本对应不可变 PayPal Plan；首惠用一次 WEEK 的付费 TRIAL，随后无限 REGULAR WEEK。无优惠直接 REGULAR。不得用 setup_fee 或一次性 Orders 伪装订阅；不创建免费试用权益 | 付费 TRIAL 首笔实际扣款及周期官方验真；测试价格明确标 TEST |
| 调价 / 老价 | 调价新建 Plan，原订阅保留原 plan 与金额快照；禁止调用共享 Plan 的 update-pricing-schemes 改老价 | 对账若发现外部改价进入核对；不能静默跟随 |
| 首惠 / 双渠道 | 沿用 billing_accounts 的成功付款事实和账号锁；`billing_one_open_subscription` 继续按 viewer 唯一，不能加 provider 把约束变成每渠道各一份 | PayPal/Stripe 并发建单、首惠、退款后不恢复资格须数据库并发测试 |
| 新购 Offer | 保留服务端签发 30 分钟、本人/版本/资格二次核验、金额币种快照 | 无需改 CheckoutInput，首阶段由服务器配置新购渠道，不接受前端指定金额或任意渠道 |
| 支付页截止 D02 | **PayPal Subscriptions 的创建参数未提供 Stripe expires_at 等价字段；不能捏造 31 分钟的平台失效事实** | 申请 PayPal 专项：`Checkout.expires_at=null`（已有 nullable），前台不显示保证失效的倒计时；站内 31 分钟后停止提供旧链接并安排取消/核对，旧平台页面仍可能完成。未核实终结前一直占位；已发生真实付款按原快照处理。00 接收并由 04 核对文案后才接线。若必须平台强制 31 分钟，则目前不具备等价方案，不放行 |
| 创建超时 / 重试 | 外部请求意图、参数、PayPal-Request-Id 在发送前落地；同意图原键原参数重试，存外部订阅 ID 后只查原对象 | API 当前声明请求键保留 72 小时；超过窗口、未知创建结果不得重新 POST 产生第二份。先按 merchant/app 与 custom_id 核对，无法唯一定位则保持待核对 |
| 取消未来续费 F01 | 候选：本站取消调用 `/suspend`，成功后再读到 SUSPENDED 才报告已停止；本期权益仍用原付款区间。接口超时保留 pending 和版本化操作 | PayPal `/cancel` 是硬取消，不能冒充可恢复；用户在 PayPal 外部硬取消时不展示恢复。**将站内取消映射 soft cancellation 须 00 明确接收** |
| 恢复 F01 | 原订阅未到期、无欠款/待付/核对、仅因本站取消暂停且确认原价/原下次时间时，才允许签发 5 分钟 ResumeQuote；主动提交后 `/activate`，前后再读验证；旧操作不覆盖新取消 | 官方资料支持 pause/reactivate，但未证明本项目“原日程、不立即扣款”。沙盒必须验证，否则不开放 resume；失败暂停、退款暂停不默认属于可恢复原因。不能新建订阅代替恢复 |
| 到期未恢复 | 本站已付期结束后硬取消原暂停订阅并核对；原对象未明确终结/待款未清前阻止新购。后续重购当前常规价 | 定时任务失效、边界恢复/硬取消竞争、迟到付款必须可补偿；不能只靠本地时钟释放占位 |
| 首购 / 续费付款 | webhook 只是核对线索；查原 subscription、Plan、交易明细/付款对象，对齐 merchant/app、custom_id、站内订单/账号、付款 ID、USD 实付、原价及实际周期 | PayPal Sale 不含 Stripe invoice line 的等价服务期；须记录订阅起点/周期执行快照，并按官方交易和周期记录关联。迟到/合并欠款不能用收款时刻或当前 next_billing_time 倒推所有历史周期。不能唯一归属周期则 review，绝不 now()+7d |
| 失败 / 用户动作 | APPROVAL_PENDING/APPROVED 只表示授权进度；ACTIVE 也不等于付款。FAILED/欠款不延长权益；PENDING/未知保留占位 | 拟 `auto_bill_outstanding=false`，避免多个周期自动合并收费；failure_threshold=1。仍需官方测试重试与欠款，失败后实际有效补款只能恢复对应原周期，不主动 capture 欠款 |
| 退款 D04 | 受控平台入口由本人处理；本站无直接退款新权限。实际部分成功退款保留本期并停续费；累计全退仅撤该付款区间，其他区间保留；未成功不撤权 | 将 D04 平台入口由 Stripe 扩为各付款所属商户平台。Refund/Sale 关联、金额币种、重复/更正/迟到均重新核实；REVERSED 不能凭事件名当普通退款，需核实 reversal 事实与处置规则 |
| 支付事件 / 广告 | 核实付款、权益、PaymentSettled.v1 outbox 同事务；继续区分首购/重购/续费与测试标记；投流失败不回滚账务 | 05 不新增 PayPal 专用付款入口，不直接接收平台 webhook；跨渠道仍用站内 payment_id 去重 |

上述 PayPal 能力依据：[Subscriptions API 与公开 schema](https://developer.paypal.com/api/subscriptions/v1/)、[一次优惠阶段](https://developer.paypal.com/subscriptions/trial-period)、[暂停与恢复](https://developer.paypal.com/subscriptions/pause-or-resume)、[失败与欠款](https://developer.paypal.com/subscriptions/payment-failure-retry)。API 路径版本为 `/v1/billing`，2026-10-26 所见公开 schema info.version 为 1.11；它不是 Stripe 式日期版本，也不是账号功能获批证明。表中本项目约束和防护方案是设计，不是官方能力承诺。

## 通知、付款事实与对账

- 新增唯一 `/webhooks/paypal`，保留 `/webhooks/stripe`。PayPal 验签需要完整原始 body、五个指定传输头与服务器配置的 webhook ID；不能塞入现有单字符串 Stripe-Signature 参数，也不能信 webhook body 自带“test”字段。HTTP 2xx 只在验真且 durable inbox 写入后返回，验签服务未知返回可重试错误，不记付款。
- 先采用官方 verify-webhook-signature 回传路径，固定 sandbox API origin，严格保留 webhook_event 原始 JSON 片段、禁止随 payload 的链接抓取任意地址。接入时只允许验证结果 SUCCESS；完整头、原始 body、access token 不进入日志。模拟器不属于真实 App，不能拿模拟器事件冒充官方订阅交易。[官方 webhook 文档](https://developer.paypal.com/api/rest/webhooks/rest/)
- 事件映射：`PAYMENT.SALE.COMPLETED`→查交易后付款核对；`PAYMENT.SALE.REFUNDED`→退款对象与所属 Sale 核对；`PAYMENT.SALE.REVERSED`→冲正核对；`BILLING.SUBSCRIPTION.CREATED/ACTIVATED/UPDATED/SUSPENDED/CANCELLED/EXPIRED`→重新读订阅；`BILLING.SUBSCRIPTION.PAYMENT.FAILED`→失败/欠款核对。未识别事件仅记忽略，不能开通权益。[订阅事件表](https://developer.paypal.com/subscriptions/webhooks)
- inbox 与外部交易去重必须带 provider/environment/merchant scope；同事件不同投递 ID 仍只处理一个事件事实。所有成功、失败、乱序通知都以重读后的事实为准。事件先于建单回写时留待重试，不丢弃。
- 对账沿用轮转与重试模式：未决创建意图 → 待决取消/恢复 → 订阅状态与交易窗口分页 → 付款退款 → outbox。保留水位并重叠回看，处理 API 查询窗口上限及分页完整性；断页不能当“没有付款”。外部响应矛盾或漏周期时进入 review，不自动释放占位。
- 退款对象详情与历史退款列表的权威获取方案需按 Subscriptions 的 Sale 资源确定，不能拿 Orders v2 Capture 的结构替换。没有完整事实时不靠总退款金额猜造退款对象。

## 实际源码改动边界

| 所属 | 变更申请 / 具体边界 |
|---|---|
| 03 内部适配 | 保留 StripeGateway/Cashier。新增 PayPal 协议/测试、受控 sandbox transport、Plan/创建意图映射、通知验证与 provider-aware 路由。现 PaymentGateway 的 invoice/customer/session 与 SettlementService 的 Stripe status/billing_reason 强耦合，应抽取内部付款/周期事实（不复制付款账本），Stripe 用适配保留行为，PayPal 不伪造 Stripe invoice |
| 03 内部 RuntimePolicy/Provider | 当前硬编码只允许 stripe_test；需按 00 接收的 sandbox-only 策略增加 paypal_sandbox、严格实际传输检查、固定官方主机及 no redirects、TLS 开启、local/testing/受控 staging 限制。缺 client ID/secret/webhook ID/merchant scope 必须明确未配置，生产仍拒绝。测试替身只 testing |
| 00 配置/启动 | `.env.example`、开发与部署样例/Guard 合作。建议 H5_BILLING_NEW_PURCHASE_PROVIDER 与两个独立启用开关；已有订阅按持久化 provider 派发，切换新购渠道不改变旧订阅处理者。PayPal 凭据配置只在受控环境，由07引导本人，不贴聊天。不新增 SDK 依赖，先复用锁定 HTTP 库；有依赖需求再申请 |
| 00 路由/安全 | bootstrap/app.php CSRF 白名单精准增加 `webhooks/paypal`，不豁免整个 billing；通知通过验签认证，观众 POST 仍 session+CSRF+归属。`RuntimeEnvironmentGuard` 的新环境要求/安全诊断及配置校验由00统一接线；不得把“测试字符串格式”当实际 sandbox 商户验证 |
| 00 冻结说明 | D02 的 PayPal null expiry、软取消/硬取消及可恢复条件、D04所属平台入口和冲正核对；若需新增统一状态/错误码由00发布。优先保留 Membership/Offer/CheckoutInput/ResumeQuote/PaymentSettled.v1 字段，无擅自共享修改 |
| 03 迁移 | 新增归属明确迁移（不改旧迁移）：持久化 provider、environment、merchant scope、外部资源种类；补不可变 Plan mapping、PayPal 周期核对证据及操作原因。历史记录明确 backfill Stripe/test/既有 scope；外部 ID唯一约束改为复合 scope。原站内UUID/外键/outbox不变；viewer唯一进行中订阅约束保持跨渠道。原 provider_invoice_id 需兼容旧行且不误称 PayPal Sale 为实际发票 |
| 01 | 不加 Billable、不变账号/员工权限，不允许支付账号 email 替代站内身份；沿用 AuditRecorder 白名单，若新增动作需01/00确认 |
| 02 / 04 | 02只消费 MembershipReader；04检查 null expires_at、PayPal托管确认跳转、未知状态与不可恢复提示。服务端返回安全审批链接，浏览器回跳不能当付款完成；前台若现有链接域名检查仅Stripe，由04按00契约扩展 |
| 05 / 06 / 07 | 05事件契约维持；06组合/安全/升级回滚及HTTPS验收；07获取本项目商户资料、Sandbox REST App/买方测试账号、独立HTTPS回调条件。均不动周四手测站；GitHub主线版本标签和上传交00 |

升级测试需覆盖：旧 Stripe 数据不改金额/权益/外部标识；新库与旧库升级；相同外部 ID 不同渠道不串单；切换默认新购渠道后旧订阅仍能取消/核对；双渠道同时建单只有一个占位；回滚不能丢弃升级后已记付款。不能简单把全局 driver 切成 PayPal 并让旧账务走新渠道。

## 分段交付与验收

1. **现在**：本申请 + 不接运行入口的模块纯协议实现/隔离异常测试；不会发送网络、不会伪造会员、不会改共享字段。00尽早审阅 D02/F01/双渠道模型与补验要求。
2. **契约接收后**：provider scope 迁移、适配与账务串接、首购至权益先交00；再补取消/恢复、续费失败、退款/冲正核对与补偿。测试含账号/金额/币种错配、重复并发乱序、请求超时和旧幂等键、原价、旧操作版本、outbox故障。
3. **官方 sandbox**：07条件就绪后在独立环境用真实 sandbox 订阅、REST查证、真实App webhook 验证首购/续费/取消/恢复/部分全退/失败/重试。留脱敏对象关联、时间区间、结果及版本。官方模拟错误仅证明对应错误处理；目前未发现可直接承诺的 Subscriptions 加速周周期工具，按官方支持办法或实际等待周期验证，不用旧 Classic Sandbox 文档或本地改时钟冒称周续费通过。[官方测试说明](https://developer.paypal.com/platforms/subscriptions/test-subscriptions/)

## 00 需先回复的审阅点 / 07 非敏感输入

- 是否接收 D02 的 PayPal 专项（null平台期限、31分钟站内停止展示并核对，非保证强制失效）；若不接收，需要维持该渠道不可用直至平台等价方案确定。
- 是否接收“本站取消→suspend；原期内经验证恢复→activate；到期核对硬取消”的 F01 映射；没有原时间/不立即扣款官方证据时保持恢复未开放。
- 是否接收 provider/environment/merchant scope 与跨渠道单订阅约束，以及 D04 退款/冲正差异处理。
- 07 请向本人取得 H5 专属 PayPal 商户注册国家/地区、企业或个人类型、是否已有本项目 Business + Sandbox REST App、预期品牌与HTTPS测试域名。不要索取聊天里的密钥、证件或银行账号；商户审批与正式收费仍另行验收。
