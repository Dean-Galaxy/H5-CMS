# 03 Stripe 官方测试与对账手册

2026-10-07。本手册记录基于公共契约 **1.0.4** 的 **03-002 消费者实现已完成、待 00 接收**状态，以及后续官方测试方案；不表示主目录或部署环境已采用候选代码，接收状态以总台账为准。**这不是 Stripe 官方测试通过报告。** 公司对应 Stripe 账号、批准 SMTP 服务及其配置仍未准备，本轮没有连接 Stripe/SMTP、进行官方验证或开启正式收费；未使用个人账号、旧密钥或正式资产。所有官方测试结果目前均为「未执行／未验证」。

## 固定实现及版本

- 锁定依赖 Cashier 16.8.0、stripe-php 21.3.2；依赖文件未修改。使用 Cashier 的客户端与固定 API 版本，付款和权益由 Billing 自有状态机管理；未启用 Cashier 默认路由或第二套 Identity Billable 状态。
- API 固定 `2026-08-26.dahlia`。这是本项目兼容版本，不宣称是最新；请求及 webhook 端点必须匹配。签名通知的版本不匹配会拒绝。[锁定 SDK 的版本源码](https://github.com/stripe/stripe-php/blob/v21.3.2/lib/Util/ApiVersion.php)、[Stripe 版本规则](https://docs.stripe.com/api/versioning)。
- 托管 Checkout：subscription 模式、card、USD、quantity=1、week×1、不自动加税、不放开用户折扣码；首次优惠用本订单专属一次性减免，续费以原订单常规价创建的 Price 为准。无站内保存卡号、付款凭据或持久媒体地址。
- D02：本人 Offer 有效 30 分钟；远端客户/优惠准备好后保存完整创建参数、幂等键和准备时间，固定截止 = 准备时间 +1860 秒。平台要求创建后最短 30 分钟、最长 24 小时；本项目 60 秒是通信余量。[Checkout 创建参数](https://docs.stripe.com/api/checkout/sessions/create)。重试不重算截止、不换幂等键；未知先查原 Customer 下对应订单 Session。

## 当前配置与离线检查

Billing 已按公共配置默认装载，不再需要本地模块开关。支付驱动仍默认 `unconfigured`，只有显式 `stripe_test` 才可能进入官方测试调用；模块已装载、接口 ready 或框架已安装均不等于支付可用。已有私有 `.env` 可保留历史 `H5_LOCAL_MODULES=billing` 值，不要求改写用户文件；测试运行时须清空该覆盖，例如在本工作区根目录使用 `H5_LOCAL_MODULES= python3 scripts/dev.py test`。staging 的该值必须为空。

在本工作区执行以下**离线**命令，只检查当前进程已加载的配置和实际 SDK 设置，不读取 Stripe 账号、不连接数据库/SMTP/Stripe，也不创建付款资产：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-03-billing/H5
/opt/homebrew/opt/php@8.4/bin/php h5-platform/artisan billing:check-config
```

| 结果 | 含义与处理 |
|---|---|
| 退出码 0，`configuration_valid=true`、`payment_configuration_ready=true` | 配置与实际客户端检查通过；仍未核实凭据有效性、公司归属、真实 TLS/邮件/地区或任何官方付款 |
| 退出码 1，`diagnostic_code=BILLING_NOT_CONFIGURED` | 安全启动允许，但尚未配置；按 `missing` 中的 driver/secret/webhook secret 类别补齐，不能据此尝试付款 |
| 退出码 1，固定配置或客户端错误码 | 配置不安全或客户端与受控设置不符，须修正后重新检查；不输出配置值或凭据 |

输出还包含 `missing`、`official_verification="not_performed"`、`network_performed=false`。这些字段明确区分配置 readiness 与官方验收；退出码 0 不是官方认证成功，也不是购买入口放行。若公共环境或非空凭据等不合格导致应用无法安全启动，命令执行前即被固定诊断码拒绝，可能没有上述 JSON；不能把缺少输出当作就绪。

## local、testing 与受控 staging 的边界

- local/testing 均要求 `H5_CONTROLLED_STAGING=false`。`stripe_test` 的 API secret 与 webhook secret **任一为空**时允许安全启动，但所有官方支付读取、写入及 webhook 接收均返回未就绪/503；新 checkout 不创建订单或订阅占位，webhook 不写 inbox。没有“本地只配一把密钥也能调用”的例外。非空却格式错误的值拒绝启动，不能当作缺项忽略。
- staging 的 `stripe_test` 准入要求精确 `APP_ENV=staging`、显式 `H5_CONTROLLED_STAGING=true`；开关默认关闭，开关本身不填凭据、不证明地区或账号归属。公共条件合格且 driver 为 `unconfigured`，或测试凭据尚缺时，安全应用可启动，付款仍未就绪。关闭开关却选择 `stripe_test` 会被拒绝。
- staging 必须遵守 [公共运行环境 1.0.4](../contracts/controlled-staging-v1.0.4.md)：配置、容器、进程的 `APP_ENV` 一致；debug、simulation、本地美国地区均为 false，`H5_LOCAL_MODULES` 为空；规范 HTTPS 域名与默认 443 端口、secure/HttpOnly/SameSite cookie、直接 TLS 入口与匹配 Host、未信任代理。Forwarded/国家头不能替代真实来源。
- staging 邮件必须是该公共版本批准的 SMTP 配置：安全域名和端口、非空受控凭据、强制 TLS 和证书校验，不能使用 preview/log/array 或不安全回落。配置通过不等于实际送达；公司 SMTP 资源尚缺，不能拿合成测试值作为部署凭据。
- Provider 启动及每次 Gateway 操作均重查公共条件、固定 API 与官方 `https://api.stripe.com` 端点，并核对 Cashier 返回的实际 SDK key/base/version、额外账号/context 和 TLS 配置。非 testing 不允许 FakeGateway、测试 HTTP 传输、自定义不安全 cURL 选项、关闭证书核验等替换；直接实例化 Gateway 不能绕过检查。
- Checkout 返回地址只取通过检查的本站 origin、清洗后的站内路径及订单 ID；交给浏览器的支付链接只接受官方 `https://checkout.stripe.com`。旧订单/持久化请求的返回地址或订单关联不合格时，保留原参数、幂等键、期限和待核对占位，允许受控只读核对但不重新 POST，也不返回不安全旧链接；`checkout_url=null` 不是付款失败或取消成功。
- staging 购买还必须取得 `US/trusted` 地区事实。当前公共地区服务在非本地仍为 `ZZ/unresolved`，可信美国来源尚未就绪，**目前不能进入 staging 购买或建单流程**；不能用本地地区开关、浏览器国家值或模拟来源绕过。公司 Stripe、SMTP、真实入口与地区验收由用户/00/06 后续落实，配置检查不是部署放行。
- production 不允许 `stripe_test`，没有 live driver；任何 live key 均拒绝。安全 production 仅可保留 `unconfigured`，不开放正式收费。

使用配置缓存时，FPM、队列及调度进程必须在进程环境中显式提供与缓存相同的 `APP_ENV`。只改 `.env` 不会刷新已加载配置；由 06 暂停入口/调度、停止旧 worker、重建缓存并重启全部常驻进程，确认旧 PID 退出后再开放。运行时检查不撤销已发出的请求；未知结果保留原订单、占位和核对路径，不能清空后重建。

[03-002 申请](../contract-requests/03-002-受控测试站支付接入申请.md)保留设计阶段记录；公共 1.0.4 文档中“03 消费者尚待实现”的说明是其发布时状态。本手册描述当前 03 候选实现，最终接收与部署结果仍由 00/06 分别记录。

## 账号准备后由谁做什么

1. 用户持有项目对应 Stripe 测试账号或 Sandbox，并确认可在其内测试周订阅。不要把密钥、签名秘密或密码发到聊天、交付单、源码、截图或日志。
2. 账号持有人/06 确认归属后，才在独立受控环境安全注入 `H5_BILLING_DRIVER=stripe_test`、`H5_BILLING_STRIPE_TEST_SECRET`、`H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET`；两类 secret 均须配置。local 演示可显式使用 `H5_LOCAL_US_TEST_REGION=true`，它只是本机演示地区，不能用于 staging。无需再新增模块开关，不复制他人 `.env`、数据库或可写素材目录。先运行上述离线检查，再按批准的官方测试步骤操作。
3. 用官方受控测试 webhook/CLI 转发到本工作区 `/webhooks/stripe`，核对 API 版本。转发工具临时签名 secret 与 Dashboard 端点 secret 不混用。公网 HTTPS 测试站及其入口由 00/06安排，本地端口不能当公网可访问证明。
4. 配置后按前述缓存/进程流程停止再启动本工作区及常驻 worker/scheduler。负责人在后台明确保存测试价格，不从其他工作区复制数据或配置。在相应环境的邮件、地区与入口已通过所需验收后，用全新已验证测试观众从正常网站购买按钮进入 Checkout；前端成功页仍不是付款证据。
5. 每个用例保留环境/Sandbox 标识、代码提交、UTC 时间、站内订单/付款/订阅 ID、对应 `cs_`/`in_`/`sub_`/`evt_` 测试对象引用、期望与实际金额/周期、通知投递和对账结果。去除凭据、签名、姓名邮箱与银行卡信息。测试对象引用放受控证据，不发布生产日志。

## 官方周期验证办法

优先新测试观众、新 Customer、新 Test Clock，初始时钟接近当前 UTC。可选 `.env` 中 `H5_BILLING_TEST_CLOCK_ID` 指向官方已创建的测试时钟；新 Customer 创建传入该 ID，既有 Customer 必须本来就绑定同一时钟，不自动改绑。默认不配置时钟。

官方 Customer API 支持 `test_clock`，订阅继承 Customer 时钟，Checkout 支持已有 Customer；本适配器按这些接口组合。**已验证的只是本地参数与拒绝逻辑，尚未实际验证公司 Sandbox 中 Checkout 与 Test Clock 的组合。** [Customer 创建](https://docs.stripe.com/api/customers/create)、[时钟 API 用法](https://docs.stripe.com/billing/testing/test-clocks/api-advanced-usage)、[Checkout Customer 参数](https://docs.stripe.com/api/checkout/sessions/create)。

如该账号或流程不支持预绑定组合，按官方 Dashboard 测试订阅详情的 **Run simulation → Advance time** 路径验证已建立的测试订阅，并保存该平台测试能力实际可用的证据；若无此入口则继续保持周期官方验证未完成，不能改本地系统时间冒充。[官方模拟操作](https://docs.stripe.com/billing/testing/test-clocks/simulate-subscriptions)。

每次推进不超过官方允许的两个最短周期，等待时钟回到 ready；续费发票可能先 draft，再推进约一小时完成最终化。按 Customer/Subscription/Clock 过滤取对象，避免把列表遗漏当作没有付款。既有方案记录的限制包括每时钟最多 3 个 Customer、每客户 3 份订阅；执行前须按链接中的官方文档重新核对实际限制，本轮未联网复查。Finish simulation 会删除相应测试对象，因此先保存脱敏证据，并由账号持有人决定是否清理。[限制和推进说明](https://docs.stripe.com/billing/testing/test-clocks/api-advanced-usage)。

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

普通成功可用官方 `4242424242424242` 测试卡；美国 3DS 测试执行时按官方表格选择，例如 `4000008400000027`，始终认证卡 `4000002760003184`。仅在测试模式填写这些官方测试卡；SCA 必须从本站 Checkout/API 流程触发，Dashboard 直接建付款不能替代交互验证。拒付和 off-session 失败场景同样按官方测试说明选择，不使用真实卡。[测试卡与认证](https://docs.stripe.com/testing#regulatory-cards)。

## API 及事件映射

| Stripe 输入 | 本地处理 |
|---|---|
| `checkout.session.completed`、`async_payment_succeeded`、`async_payment_failed`、`expired` 等 `checkout.session.*` | 以 Session ID 定位原订单，再读取平台 Session/订阅/发票；浏览器跳转和 completed 本身不发权益 |
| `invoice.paid`、`invoice.payment_succeeded`、`invoice.payment_failed`、`invoice.payment_action_required`、`invoice.updated` 等 `invoice.*` | 重新读取 invoice、InvoicePayment、PaymentIntent、Charge；只接受真实已成功测试付款及精确金额/归属/币种/周期，失败或需操作只更新状态 |
| `customer.subscription.created/updated/deleted` 等 | 读取当前 subscription 的 items 周期、取消标记、status 和 latest_invoice，必要时核对付款；active 本身不是已付权益 |
| `charge.refunded`、`refund.created/updated/failed` 等 | 以 charge 查全部分页退款对象，按唯一退款对象当前状态重算 succeeded 总额，执行 D04 |
| 其他已签名且匹配版本的事件 | 标为 ignored，不生成付款事实 |

订阅周期按本项目锁定接口 `items.data[].current_period_start/end`，权益以 invoice line `period` 为准。付款链核对 InvoicePayment → PaymentIntent → Charge；手工标 paid 或信用余额不能伪装成已验证收款。[Subscription](https://docs.stripe.com/api/subscriptions/object)、[Invoice line](https://docs.stripe.com/api/invoice-line-item/object)、[InvoicePayment](https://docs.stripe.com/api/invoice-payment/object)。

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
- F05兼容：新有/无优惠请求均省略 `allow_promotion_codes`；不设置时不开放手填优惠码。旧已保存coupon+false请求只允许读取对账，不删除字段重发，也不换key或延长期限，即使尚无attempted标记亦如此。SDK桩和真实数据库用例已覆盖，官方公司账号验证仍待补。参见 [F05证据](../evidence/03/f05/README.md)。
- 未知的 Checkout 创建只查询原 Customer 下对应订单。发现唯一 Session 才复用；未找到并不足以证明没有付款，不自动释放占位。若进程在「已标记尝试」与真正远端 POST 之间退出，可能进入需要人工平台核实的待定状态；首版没有强制释放/伪造成功按钮。账号准备后的官方故障演练需确认这个处置流程。
- 实际退款由账号持有人在受控 Stripe 入口依法按实际情况办理，本站只同步；不恢复普通退款审批流，不宣称所有款项均不可退款。最新平台事实可能修正退款总额，但不恢复续费或首次优惠。
- 客服核验在账号锁之后重新读取并锁定，一次核验仅受理一次取消；受理即消费，并先留审计。平台未知由原 operation 重试。旧客服页面、旧 F01 或旧退款重试均不得覆盖用户之后的新决定。
- 不能用 SQL 手工将订单标已付、延长权益、清待核对或复位首惠。发现数据矛盾交 00/03检查，对照平台事实后通过受审查的补偿路径处理。
