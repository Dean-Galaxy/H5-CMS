# 06 analytics-v1 消费者接线准备

2026-10-29。接续[第一阶段准备](06-analytics-v1组合验收准备.md)。按00指令先对照已接01 `8a2f108`、03 `d09d5ff`、04 `d74efb22`，正常合并 `95cde420078170cba0fb8007f6e29e64f545c93f` 及同意纠偏 `0e026b76e114e37613b7cba73796f064d4e52a59`，再合并点击匹配正式契约 `fbafae0f47cf4818563c7353528d62c5527f8996`。**当前05尚未接收，未启动这个缺消费者的候选。** 实际本轮接线源码 `4ba3ede74067ecf7d2c88733b858a1fd8361f550`。

## 实现与未执行边界

新增 `tests/Integration/AnalyticsCombined/`，与第一阶段关闭消费者测试分开。12个用例已写，尚未执行；缺05实现直接失败，不用成功spy或skip代替。所有消费者保留真实绑定，仅外部支付协议用03已验证的测试FakeGateway响应；新ControlledPayment只结算观众HTTP真实创建的现有test订单，金额从订单读取，不直接写paid/权益或构造来源。只允许testing+明确专属_test+PayPal关闭，普通runtime拒绝。

| 接线 | 对应断言（待完整候选执行） |
|---|---|
| 真实来源、身份与账务 | 05合法资产服务创建测试像素/链接；/r进入与真实consent/events；01真实注册及签名处理器验证；04 HTTP创建订单；03真实结算/outbox→05；付款前后第6集许可、同subject/viewer/link、重放不新增 |
| 付款后退出、事件迟到 | 付款事实入账但PaymentSettled未分发→真实logout→context unknown→新账号→分发原通知；原付款仍按原viewer/link记录并生成原授权待发，新号不继承、不把logout当撤回 |
| 明确撤回 | 付款后真实撤回analytics/ads再分发旧通知；不写付款行为/不外发，账务/权益仍保留 |
| 退款当前累计与净收 | 两个独立合成退款100/200，从100→300→失败纠正0；03真实Reader与05报表核gross300、net200→0→300，稳定财务事实数量 |
| 广告同意、分析拒绝 | 独立POST landing产生source，subject/behavior都为0 |
| 浏览器伪造权威与属性 | 全部server事件、viewer/source/amount/country/许可play_session_id/token拒绝，不产生财务事实 |
| 同UUID网络重试 | 同UUID同内容两次重放仍一条，改内容拒绝 |
| 消费存储故障 | 自有_test内临时失去behavior存储→真实付款/会员许可不回滚、可靠通知保持待发→恢复后仅一条付款行为 |
| 拒绝与跨站 | 拒绝采集不建事件；实际消费者Origin拒绝；PHP框架仍会跳过CSRF，不能据此宣称CSRF通过 |

05表名/报表键目前仅读其源码对照，用例没有复制其数据或逻辑，也没有合入其未接收提交；收到最终SHA必须重新对照该已接收版本。共享接口没有新增缺口，未改业务模块/公共文件。

## 浏览器注册与实际预览邮件

现有私密计划支持真实页面goto/click/fill；新增 `membership_offer` 只跳固定 `/membership?purchase=1`；新增 `verify_preview_mail`，参数email只能本区合成 `@example.test` 收件人。该步骤只查本次浏览器开始以后新建的本区 `.runtime/mail`、0600且非symlink/硬链接的.eml/.html；加载邮件并点击其中符合本机origin、Identity签名路径及expires/nonce/signature的真实链接。签名URL只在内存使用，不写报告或命令行；原浏览器上下文Cookie保持。

浏览器真实注册→点击预览签名链接，与PHP `registerAndVerify()` 明确分开。后者用了Notification::fake和真实签名处理器，只用于服务组合；不得以写库email_verified_at或它的通过替代真实浏览器。浏览器工具即使链接响应正常也仍需实际email_verified行为及账号验证状态证据；其输出只记签名链接点击成功，无签名正文。

本轮仅13项纯工具自测（6 Python+7 Node）及6份PHP语法通过；没有执行12个消费者测试、浏览器、数据库或网站。证据见[本轮回执](evidence/06/analytics-wiring-20261029/README.md)。首阶段8项170断言只证明首阶段bb947dc，不背书本次合并后的候选。

## 完整SHA到达后的继续顺序

1. 00指定唯一可运行完整候选，正常合并，检查PHP依赖与新迁移/05路由/真实绑定。仅原06独立库端口，当前真实支付窗和周四站继续不动。
2. `analytics_acceptance.py --candidate <实际完整HEAD> --stage combined`：缺消费者仍77；能力齐备才执行新增组合目录及原准备测试。显式本站analytics启用，外发/商户仍关闭；实际结果即便全绿也只标 `integration_only_browser_and_matrix_pending`，不是完整浏览器/矩阵验收通过。
3. 对照最终05实现，补并执行7天精确边界、同入口/同秒/跨日漏斗、权限聚合、财务/生命周期版本重放和本地平台HTTP替身；继续逐项记录总矩阵，不把少数代表测试扩为全通过。
4. 在本区独立_test准备真实内容服务/合法fixture产生的发布剧、6集前5免费、新合成可解码HLS和私密测试价格。注意Content MediaStore要求 `media.mp4`、`index.m3u8` 和 `segment-%05d.ts`；首阶段的自生成文件尚未装成已发布Content媒体，不能直接拿准备manifest当播放通过证据。不从他人区搬素材/账号/数据库。
5. 接好显式testing本区受控Gateway服务进程与完成现有订单的私密控制步骤，浏览器完成真实落地→同意→解码→门槛→表单注册→预览邮件点击验证→HTTP结算建单→受控协议确认→实际会员播放。保留真实目录/身份/账务/采集/报表，只有provider响应替身；不修改公共bootstrap或打开商户API。此进程目前未启动，完整浏览器fixture仍待最终候选接线。
6. 在真实HTTP进程核CSRF/跨站和采集网络中断、重试、撤回/换号、广告拒绝独立landing等，留浏览器与持久数据双证据。当前Laravel只在console+testing时跳过防伪；真实cli-server需现场确认正确防伪响应，不能沿用PHP测试结果。
7. 取得本区新增UI安全截图再由00交08有限复核；06不自行改08原稿或把功能通过当UI/人工通过。外部Meta/TikTok资产与真正手机检查分别等待同事/本人配合。

本轮只提交06工具/测试/文档，实际环境未动；未来启动也只用本区自有.test资源。代码/文档交00串行接收与GitHub留存后再按其候选执行，不因接线已写就自行部署或宣布平台通过。

## fbaf 点击匹配追加

新增MatchingContextTest三项（也未执行）：当前合成fbclid优先不匹配旧fbc、Meta按平台隔离/加密保存，行为与完整报表无原ID；unknown/denied不保存source匹配ID；每值512bytes/整包4KiB及未知字段拒绝。generic landing_view先到后再过1秒独立landing，必须仍能为同一次pending确认正确匹配context，不能生成重复或永久空context。此为乱序PHP断言，不能代替04真实浏览器慢网顺序证据。

浏览器私密计划还增加 `promotional_entry:{code,platform,click_id}`（click_id仅明显SYNTHETIC06前缀）、`landing_delay`（独立确认请求慢1秒）、`assert_landing_before_events`。只记录landing请求/响应与普通events的顺序/HTTP状态，不记录click_context原文。完整浏览器执行时需先设delay，再真实/r进入并同意，确认独立landing已响应后才有入口事件；实际加密context及Meta/TikTok HTTP替身payload仍须双重核对，不能靠200或请求顺序推断匹配字段已送达。已存在_fbc/_fbp/_ttp的合成cookie分支、TikTok payload、拒绝后缓冲/日志无标识和旧单快照保护，仍列总矩阵待执行。

## 03同秒精度缺陷追加（仍未执行）

接线增量源码 `7b02431005d4ab2583c55acfc6c55d2dc5f3469a`；在此前12个用例之外新增2个，实际仍全未执行，只新增PHP语法检查。最终完整候选必须包含03保留真实微秒的修复和新增precision(6)迁移，不能只接05或用旧occurred_at.startOfSecond版本运行后声称通过。

新的LifecycleMicrosecondsTest将同意及合法来源/身份/订单链固定在同一秒.100，.200由真实本地SettlementService处理confirmed的合成退款，核03 outbox属性/DB及05行为均为200000微秒、退款事实delivered且重放不加计、已付时间/权益区间保持。另一用例核新schema精度6和既有200800源码摘要不变，paid_at/权益字段不顺带改精度。05仍按真实发生时同意严格判断；不能把consumer放宽到整秒来掩盖生产者回拨。

本轮没有运行迁移、应用或测试库，没有修旧outbox/Skipped记录，也不把旧秒精度证据虚构成可恢复的微秒。详见[新增语法/未执行回执](evidence/06/analytics-precision-wiring-20261029/README.md)。

## 独立landing优先接线修正

源码 `c5ccb4d771ff1bc5c0c7acb7a81fc6a238748ce6`。旧helper在analytics=true时只发generic landing_view的假设已删除：现在总是先独立POST landing（可选click_context只进此请求）并confirmed=true，真实context显示analytics granted后才发generic行为；广告only不发行为。乱序用例明确先503且无source/behavior/event-key，独立确认后原UUID重复仍只一条。仅两文件语法检查，不重标前述消费者为通过。[增量回执](evidence/06/analytics-landing-wiring-20261029/README.md)。

05旧接线实跑失败日志不回写。最终浏览器候选还须包含04修复隐私草稿被慢GET覆盖及加载/保存反馈的问题；本区先修PHP接线不等UI，不启动旧候选。14个消费者测试依然待完整候选实跑。
