# 站内行为分析与广告回传契约 v1

2026-10-29，00 接收定义；附加契约，不移动共同业务标签 1.0.5。用户已明确授权补齐，并确认 D05：**同意后 7 天内最后一次有效推广点击；建单冻结；普通访问不覆盖；撤回后停止广告回传。** 本站规则不冒称平台归因。本契约授权代码开发及隔离测试，不授权广告投放、Live、修改当前人工支付站或公开用户资料。

## 所有权与交付

00：本契约、公共接口/绑定、公共布局挂载、接收与串行集成。05：来源/同意状态/行为事件库、采集 API、报表、回传与运行诊断。04：观众页面/播放器/隐私控件及浏览器采集。01：注册/登录/验证服务端结果。03：权威付款和生命周期事实及只读财务接口。06：独立组合/浏览器/故障验收，不部署正在 C2 人工退款的真实 06。所有界面沿用 08 已接收组件；新增分析在投流管理内分栏，不另造后台样式。

## 共享接口

`BehaviorEventRecorder::record(string $eventName, array $properties=[], ?string $deduplicationKey=null): bool` 为受信服务器调用。05 实现并绑定；未启用时公共 no-op 返回 false。采集失败/未同意不得阻断业务；实现必须捕获内部错误，事务中失败使用 savepoint 避免污染付款事务。关键付款通过现有可靠 PaymentSettled outbox -> 05 收件及行为记录同事务完成，不能只在浏览器成功页发 Purchase。03 的退款/取消/恢复等事实采用本模块可靠待发机制补传，不依赖一次易失事件。所有重放复用相同 key；服务器结果事件禁止从浏览器 API 直接提交。`clearBrowserContext()` 在退出登录后事件记录完成、session invalidate 前调用；换号/退出不得把两个观众自动合并，原待付订单/原订阅快照不改。

可靠财务事实使用 `recordFact(string $eventName,array $properties,string $deduplicationKey): BehaviorRecordingResult`：`Accepted`（含完全相同重放）、`Skipped`（无/拒绝同意或无效，不再重试）、`Retry`（模块未接收/临时存储故障，保留待发）。默认禁用实现返回 Retry，不能把尚未就绪误当成功；业务不因此阻塞。03 待发重试必须有界退避/诊断，不能每分钟无限轰炸。05 PaymentReceiver 内相同区分，临时失败保留03可靠outbox，拒绝分析仍正常收付款事实。

补充冻结：财务 outbox 无浏览器请求时，只能通过可信 viewer/source 及 **事实发生时**已存在的同意证据关联；不能借用当前无关会话，不能因后来同意补采以前未同意的行为。发送时撤回优先。永久无效也返回 Skipped，但必须记录安全诊断原因（不能伪装 Accepted）。`refund_confirmed` properties 增加 `fact_version` 正整数，表示该 payment 当前成功累计退款的递增快照版本；累计额允许因官方纠正降至0，同一版本固定key和内容，晚到旧版本不覆盖新版，100→300不能相加成400。净收始终按 Reader 当前成功累计额核算。Reader 输入原始数组最多200项、UUID逐项严格校验、重复去重；超过200或非法ID明确校验错误；损坏 snapshot 返回null并安全诊断，不能从未经核定字段补造来源。

浏览器允许的通用上下文为 `page_instance_id`（UUID）、`play_instance_id`（UUID）、`path`（固定同站无query路径）、`drama_id/episode_id`（仅引用，服务端复核目录及对应关系），以及事件自身数值/错误白名单；绝不信浏览器 viewer/source/价格/成功结果。04 和05沿用这些字段，不另起同义命名。

05-002 浏览器补充由00接收，`play_session_id` 改为公共的 `play_instance_id`（不收 Content 播放许可 ID）。同内容重复 accepted、不重复计数；跨主体/同ID不同内容 rejected。未同意不建分析主体；存储暂时失败503允许同ID有界重试；整包422/413、单条拒绝计rejected。`watched_seconds`为每play_instance_id累计实际观看增量，seek不计；服务端进行单调/合理上限校验。

接收纠偏：退出/换号**不等于撤回隐私同意**。`clearBrowserContext`解绑当前session中的subject/source/候选并使下次选择unknown，不可把已建订单的原privacy/source一律改拒绝/撤销，否则正常“付款后退出、可靠outbox迟到”会漏转化。原事件仍按事实时原授权、原对象及真实撤回判断；下一个账号绝不继承。真正拒绝/撤回仍停止旧待发：已认证观众可按自己可信viewer的既有privacy撤回，员工或别的账号不具此权；未登录只处理当前可识别浏览器的同意证据，不冒称完成全账号撤回。05-002中若将logout一律视为旧来源撤回，以本段为准。需分别验证立即退出后的旧付款、明确撤回后的旧付款、新账号不继承三类场景。

允许受信 properties 按事件白名单含 opaque `viewer_id,drama_id,episode_id,order_id,payment_id,subscription_id,source_id`；这些**绝不接受浏览器指定**身份/来源/金额/成功状态。`position_seconds,duration_seconds,watched_seconds,startup_ms,stall_ms,error_code,transaction_kind,amount_minor,refunded_amount_minor,currency,is_test,occurred_at` 依事件校验范围。事件时间以服务器接收为准；财务事实可使用经 03 核定的 occurred_at。无邮箱/密码/token/原支付 ID/媒体 URL/任意 query/自由错误堆栈进入行为库或报表。合法页面仅固定同站路径，查询参数白名单。05 对 IP/UA 的广告匹配用途加密、限制访问，不进入普通分析明细。

`BillingAnalyticsReader::payments(array $paymentIds):array`，最多 200 个唯一 UUID。结果按 payment_id 索引，缺失省略；每项仅 `payment_id,viewer_id,order_id,subscription_id,transaction_kind,amount_minor,refunded_amount_minor,currency,is_test,paid_at,attribution_snapshot`。时间 UTC ISO8601，金额整数 cents；退款只统计已确认成功累计额；snapshot 为既有 DTO array 或 null。禁止消费者读 Billing 表，05 先按员工可见 link/payment 范围选 ID；财务接口仅进程内，禁止公开或让前端枚举。

`AttributionService::snapshot(null)` 在启用后读取当前服务器会话有效来源；任意非 null 浏览器 marker 不得授予来源。时间窗 `[captured_at,expires_at)`。只有真实可见落地确认且广告同意有效才建立 source，排除可识别预取/机器人，不能声称完全识别机器人。有效推广新点击覆盖，普通浏览不延长/覆盖；无效/停用链接不可换剧暗跳。改剧/平台/像素创建新 code；旧单/订阅冻结快照不改。登录再生会话保留合法来源，退出/换号清除；续费沿用原订阅来源并单列。

## 浏览器接口（05 提供，04 消费）

- `GET /api/v1/tracking/context` 返回 `{enabled:boolean,consent:{analytics:'unknown|granted|denied',advertising:'unknown|granted|denied'},policy_version:'analytics-v1',pending_landing:boolean}`。不返回任何内部 subject/source ID、平台 token 或原始匹配信息；缺模块明确未启用。响应 no-store。
- `POST /api/v1/tracking/consent`：同站会话、CSRF、限流；body `{analytics:boolean,advertising:boolean,policy_version:'analytics-v1'}`；返回同上。两种用途分别选择，默认 unknown；拒绝/撤回正常使用网站。广告撤回实时禁止后续发送（包括旧快照）；分析撤回后不再行为采集/关联。既有必要账务保留，不冒称本轮完成全部法域隐私法律审查。
- `POST /api/v1/tracking/landing`：独立广告落地确认（会话/CSRF/限流/no-store）；body `{page_instance_id:UUID,drama_id:UUID,path:string}`，不接受link/source/像素/actor。仅详情页真正可见且advertising granted且服务器pending候选复验通过才归属；返回 `{confirmed:boolean}`。analytics denied时也可确认广告来源，但**不记录分析事件**。04在重读context后可见详情+pending时调用；不要借通用行为事件绕过analytics拒绝。原events中analytics granted的landing_view可以走同一幂等来源确认，重复不延长7天来源。未同意/候选无效confirmed=false。
- `POST /api/v1/tracking/events`：会话、CSRF、限流、no-store。body `{events:[{event_id:UUID,event_name:string,properties:object}]}` 最多 20 项、请求最大 32KiB（05 实际强制）。response `{accepted:number,rejected:number}`；未同意不建立 analytics subject、不记录行为。拒绝客户端权威财务/身份成功事件、伪造 actor/source/金额、未知字段/超长内容；服务器生成/校验来源/身份/地区/设备分类。客户端 UUID 重传同一事件不加计；同 ID 不同内容拒绝。离线/刷新恢复使用有界短期缓冲，撤回清空，不承诺关页绝不丢失。
- `/r/{code}` 只允许内部可用剧目落地。未同意时仅服务器保存短期导航候选（非跟踪 source/不计访问）；详情页可见后前端 `landing_view` 触发真实落地确认；同意时复验候选才归属。客户端不得提供可信 link/source/pixel。无广告同意但有 analytics 同意可分析站内行为，渠道显示 unknown/unattributed，不伪造归因。

## 事件清单与口径

| 来源 | 事件 | 规则 |
|---|---|---|
| 浏览器 04 | page_view,landing_view,drama_view | 页面真正可见；同一页面实例重复初始化不重复；重进页面是新访问；page_view 不等于播放 |
| 浏览器 04 | play_requested,play_started,watch_progress,episode_completed | play_started 需 playing 后时间实际前进，非按钮/预载；progress 以实际观看累计、合理节流，不把 seek 算观看；completed 一次/播放会话 |
| 浏览器 04 | player_startup,player_stall,player_error | 区分首帧等待、卡顿、网络/媒体/地区/会员许可失败；短枚举，不带素材地址 |
| 浏览器 04 | paywall_view,subscribe_click,registration_view,registration_submit,verification_view,checkout_click,payment_return_view | 展示/点击不是成功；顺序按真实路径，不强迫注册发生在固定位置 |
| 服务器 01 | registration_succeeded,registration_failed,login_succeeded,login_failed,email_verified,logout | 仅观众 guard；注册成功、邮箱验证分开；失败枚举，不带邮箱；关键成功稳定 key |
| 服务器 04 | checkout_created,checkout_failed,payment_pending,payment_cancelled,payment_failed | 只据可信业务返回；不能把确认延迟当失败；不可替代 payment_confirmed |
| 服务器 03/05 | payment_confirmed,refund_confirmed,subscription_cancelled,subscription_resumed | payment 来自既有 PaymentSettled；续费按 transaction_kind 单列；退款成功累计金额、取消仅未来续费，稳定 key；不因拒绝分析而改业务事实 |

分析 identity 关联只在 analytics 同意后做，anon/session -> 登录观众仅本次合法链路；退出/换号旋转，不推测跨设备。事件 schema_version=1、event_id/dedup key、服务器 UTC time、session/匿名主体（伪名）、服务器校验 viewer、合法 source/link、剧/集、设备大类、服务器可信 country/unknown、locale、环境/is_test、consent/policy 都可用于查询；标识/维度由05控制白名单。不信浏览器 country/IP。测试/真实分栏，普通 runtime 模拟拒绝。

## 最低报表与可信度

按有界日期范围、渠道/链接、剧集、设备、测试/真实筛选：访问人数/会话、实际播放、付费门槛展示、注册成功、验证、checkout、首购/再次订阅/续费、付款后实际播放；单列技术错误/支付 pending/失败/取消、退款和确认净收（gross-refund，非利润/ROAS）。缺广告花费不算 ROAS。01/03 成功结果与客户端行为区分。

漏斗同一入口人群、固定 7 天观察窗及事件时间顺序；显示每步去重人数/上一步转化率、仍在观察窗口人数，不能把各步全站独立总量除出虚假漏斗。时间范围选择入口时间，后续可在窗内发生；不覆盖窗口内缺失/未知维度，分母为 0 显示“—”。完整链路漏斗与各环节独立计数分开；不能要求所有合法购买都经过每个步骤。匿名/登录合并有明确 actor key，测试至少覆盖两次刷新/重试、跨日、换号、广告拒绝、注册前后贯通。

采集诊断至少列启用/禁用/未知、最近接收时间、持久失败计数或日志、待发/重试/阻断/耗尽、测试与真实、source 缺失及原因、平台 api_accepted 与“平台尚未核验”。不能把 visits=null 显示 0。聚合只在获授权资产内；负责人全站，投流同事仅被分配链接；不提供逐人联系方式或跨员工反推数据。留存/清理有界默认原始行为 90 天、短期缓冲 24h 及大小上限；不得清理账务/未完成转化投递或改变归属的业务记录。

## 广告回传与放行

延续现有服务端 Purchase、稳定 event_id、重试预算/加密凭据/默认禁用及测试隔离；浏览器**不另发 Purchase**，减少重复来源。其他网页事件是否映射平台由05列明确映射，不把全部内部事件盲目外发。即使广告外发关闭，显式启用且同意后的本站采集/报表应可用。Consent 撤回优先于旧 snapshot，窗口到期与历史合法付款事件区别处理，禁止把旧测试付款发正式资产。续费不能当新客首购。

本轮完成标准：公共契约测试；模块回归；真实本地浏览器完整有来源链路及实际前进播放；异常/缓冲/撤回/跨身份/权限/脱敏/隔离检查；账本/财务汇总核对；同一事件重复不增加；外发关闭时零外部调用。Meta/TikTok 实际资产未具备时，仅本地替身通过，正式记录“外部联调待同事配合”，不得宣称正式投流就绪。00 复核串行接收，固定代码版本和不可移动标签，上传 GitHub 后回读；不部署当前 21f33 的 C2 支付站。
