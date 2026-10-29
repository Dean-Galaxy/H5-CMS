# 06 analytics-v1 组合验收准备

2026-10-29。按00授权在原验收工作区正式实现准备工具；业务共同契约仍1.0.5，附加analytics-v1，不移动旧标签。共同起点32e878，正常合并ecf132/f629a04/c9a1d9公共增量；保留原06分支历史说明。**这是可执行准备交付，启用消费者的完整组合与真实浏览器链路尚未执行。**

## 本轮实际版本、隔离和结果

最终准备源码 `bb947dcb7a56b04d5ce27433ab4dfdffeef26a31`。工作区 `/Users/hejunxiang/.codex/worktrees/h5-06-acceptance/H5`，分支codex/06-quality。独立web8206/PG5606、工作区h5_217e1741db；测试仅h5_217e1741db_test。新合成视频/声轨由本机lavfi生成，8秒H264及4个TS，不下载、复制他人素材/价格/账号或env。

原工作区旧web/queue/scheduler记录均已确认失效，仅归档死PID记录，没有停止存活进程；本轮只启动自有PG作测试，不启动web/queue/scheduler/任何隧道。真实06的21f33、8406/5906/8407/8408/8409和周四8377/5877没有查询/改动。没有广告平台、商户API或真实凭据操作。

固定源码实际通过：8项PHP测试/170断言（公共契约3、新关闭消费者业务3、共享权威边界2），6项隔离工具测试、5项浏览器辅助测试，均无skip。关闭采集时采用合成FakeGateway响应但真实本地Billing持久化，验证付款/权益/退款及重复结算不被缺消费者阻断，Laravel HTTP记录器本场景零请求；这不代替启用后采集故障或平台真实测试。

合成媒体已由ffprobe核8秒并记录所有文件摘要，尚无浏览器解码结果。组合闸门与浏览器闸门本轮都实际返回77，原因是缺消费者，浏览器未启动。新的四端点能力检查包含独立POST landing；报告不会把准备测试成功当“完整埋点可用”。

[证据索引](evidence/06/analytics-preparation-20261029/README.md)，[机读矩阵](06-analytics-v1验收矩阵.json)。

## 已实现的执行入口

| 文件 | 作用及限制 |
|---|---|
| scripts/ops/analytics_acceptance.py | 固定精确HEAD、拒脏源码、拒保护端口/主区/PayPal区；验证本区db/role/hash；仅专属_test；强制付款/广告传输关闭且清空继承凭据；不启动web/queue/scheduler/tunnel |
| scripts/ops/analytics_preflight.php | testing+本区_test+关闭开关守卫；清点四API、BehaviorEventRecorder、BillingAnalyticsReader与04partial；不调用财务port/商户服务，不将缺收入显示0 |
| scripts/ops/analytics_browser_runtime.php | 只读核实际本机配置与数据库绑定；浏览器启动前核本区存活web命令/路由绑定并拒付款/外发开启，不用测试环境报告代替实际配置核对 |
| tests/Integration/Analytics 与 tests/Security/Analytics | 上述准备阶段业务保护与公共权威边界测试；不覆盖尚不存在的API实现 |
| tests/Browser/analytics-journey.cjs | 自有私密计划驱动的goto/click/fill/reload/等待事件/采集离线恢复；所有HTTP限制同一本机origin，计外网尝试；play要求decoded frames和墙钟时间前进；日志只事件名/UUID摘要/许可布尔与短枚举，无原始payload或签名URL |
| 两组工具自测 | 合成配置检查保护端口/错绑定/私密文件/导航拒绝、事件日志脱敏；不是实际浏览器测试 |

浏览器计划只从本区.runtime/06读取0600文件，必须含实际candidate、synthetic_only=true及最多100步。`steps`支持 `goto:{path}`、`click:{selector}`、`fill:{selector,value}`、`wait_url:{path}`、`play:{selector?}`、`wait_event:{event_name,minimum}`、`tracking_offline`、`tracking_online`、`reload`。selector由固定候选实际页面确定；fill值只能本区合成账号，不能放入Git。HTTP数据仅元信息，不保存fill值。wait_event仅证明浏览器尝试发送，不代替05持久收件/去重/来源和权限结果；整体completed也是浏览器观察完成，不是整体验收通过。撤回、跨账号和故障需各自独立计划并配服务端断言。

## 当前阻塞和接收后顺序

能力清单实际缺：GET context，POST consent/events/landing，05 recorder，03 financial_reader，04 audience.partials.tracking。01/04服务端结果接线、05报表/权限/来源/可靠事实/诊断也尚未在本候选接收，不能只凭四API出现就算全链通过。公共接口目前没有需要06擅改的缺口；需要00先接收01/03/04/05，再给唯一组合SHA。

收到候选后正常合并，按实际模块服务/表/后台入口扩展具体组合断言及本地受控付款/验证夹具，不复制业务实现；当前 `--stage combined` 是拒绝放行闸门，即使基础能力齐备也返回77 `blocked_pending_06_combined_suite`，必须补齐本矩阵实际断言/浏览器和数据核对才能调整。本交付没有偷偷跳过消费者测试。

04/05沿用公共page_instance_id/play_instance_id；不能发送Content真实许可play_session_id。广告同意但分析拒绝必须走独立POST landing，仅形成有效广告来源、不写行为；拒两项/撤回/换号均单验。退款fact_version按当前成功累计快照，100→300不相加，旧版不得覆盖新版，必要纠正0；财务净收以03 Reader当前成功累计额为准，不能相加行为事件当账务。

本机浏览器完整链路必须取实解码帧+时间前进、服务端权威注册/验证/付款结果及05数据关联证据；受控付款仅本区FakeGateway/明确测试夹具，不调用当前官方PayPal。Meta/TikTok仅本地HTTP替身，不创建广告/外发真实数据，不宣布平台通过。平台账号/像素授权、官方事件验证和实际手机由同事/本人另行配合。

## 如何运行与停止

在本工作区根目录，先确认自己在06分支、已合入00固定候选且源码已提交。程序要求参数等于实际HEAD，拒绝旧证据标签。报告单独提交后，可将参数替换为新报告HEAD重新执行；旧结果仍仅标其实际bb947dc源码。

```sh
python3 scripts/ops/analytics_acceptance.py --candidate bb947dcb7a56b04d5ce27433ab4dfdffeef26a31 --stage prepare
python3 scripts/ops/analytics_acceptance.py --candidate bb947dcb7a56b04d5ce27433ab4dfdffeef26a31 --stage combined
python3 -m unittest discover -s scripts/ops/tests -p 'test_analytics_acceptance.py' -v
node --test h5-platform/tests/Browser/analytics-journey.test.cjs
```

prepare使用自有专属_test并可重置该测试库；绝不使用普通库。若本区web/queue/scheduler的PID文件存在，先核对再停本区服务，程序不会替你清除或kill不明进程。combined现阶段预期77，绝不是全绿。运行记录在本区.runtime/06/analytics/<UTC>/，私密日志可能包含框架诊断，只提交人工检查过的脱敏结果。实际浏览器需要已经安装的Playwright模块，可用H5_PLAYWRIGHT_MODULE指定共享工具依赖路径；不得复制其他工作区素材/私密运行文件。

```sh
node h5-platform/tests/Browser/analytics-journey.cjs <实际完整SHA> <本区.runtime/06下的私密计划文件>
```

程序还要求同候选prepare产生的readiness；消费者缺失时先返回77，不加载Playwright或启动Chrome。消费者齐后只在00允许的本区本机测试站启动，实际支付/广告开关核对不通过会拒绝。夹具、报表聚合、账务和CSRF实际HTTP断言待候选后接线，不能现在拿helper自测作实际浏览器证据。

本轮收尾只停止自己启动的PG，保留所有测试数据和新合成素材。停止本区服务可在正确目录用 `python3 scripts/dev.py stop`；这不会删除数据，不能在真实06或周四目录运行。再次执行准备会按同一本区_test重建所需迁移。本轮没有完整恢复、公开部署或正式收费结论。

## 场景矩阵

| 编号 | 场景 | 当前状态 | 验收方法与必要结果 |
|---|---|---|---|
| AN06-P01 | 关闭采集业务保护 | 准备范围通过 | PHP测试：付款/权益/退款/重复结算，游客免费与付费门槛；8项170断言中对应关闭消费者用例；不是启用后消费者验收 |
| AN06-P02 | 缺消费者/身份权威边界 | 准备范围通过 | 公共契约和目录，缺财务reader抛未就绪，默认recordFact Retry；不把未知收入变0、可靠事实不虚报Accepted；浏览器不能产生权威成功事件 |
| AN06-P03 | 工作区/候选/配置隔离 | 准备范围通过 | 6项Python、5项Node；保护端口、外发强制关、夹具权限/导航/脱敏；仅原06_test，候选精确，浏览器不读取其他工作区夹具 |
| AN06-P04 | 消费者缺失闸门 | 准备范围通过 | prepare能力清单；combined及browser入口实际返回77；不启动浏览器、不记组合通过 |
| AN06-P05 | 合成媒体准备 | 准备范围通过 | 本机ffmpeg lavfi 8秒H264，4TS，ffprobe+各文件摘要；没有解码浏览器结果；素材只在自有.runtime |
| AN06-J01 | 有来源完整链路 | 待消费者与候选 | 可见/r落地→同意→实际时间前进及帧增长→第6集门槛→注册/验证→受控付款→会员播放；同入口来源、匿名到同意后登录主体贯通；客户端按钮/支付返回不算成功 |
| AN06-J02 | 广告同意/分析拒绝 | 待消费者与候选 | 仅POST /tracking/landing；拒绝analytics；检查subject/行为库及建单来源；可见广告来源有效且不写行为，不以landing_view绕过拒绝 |
| AN06-J03 | 两项拒绝与撤回 | 待消费者与候选 | unknown/denied不创建subject；同意后撤回分析及广告；断线缓冲后恢复；拒绝不影响业务；撤回清缓冲、停止关联/广告，包括旧snapshot待发 |
| AN06-J04 | 可见性及目录校验 | 待消费者与候选 | 隐藏tab/预取/可识别机器人/不可用或停用code；剧集错配；不计有效落地/来源；可见确认后复验目录和来源，不外跳/换剧 |
| AN06-J05 | 来源窗和建单冻结 | 待消费者与候选 | 第二有效点击覆盖；普通访问/刷新不延长；7天前1微秒/到期/跨日；晚付款/续费；[captured_at,expires_at)精确；建单后快照不变，续费单列原订阅来源 |
| AN06-J06 | 换号和同意时间 | 待消费者与候选 | A登录/退出/B登录，注册前后贯通；事件发生后再同意；后台无request事实；合法本次链路合并；换号旋转，不补采后同意之前事件；撤回优先 |
| AN06-S01 | CSRF/会话/限流 | 待消费者与候选 | 实际HTTP无token/错token/外站Origin/限流/失效会话；覆盖consent/events/landing；状态拒绝、来源/subject/事件无新增，正常站内请求可用；PHP测试禁用CSRF不能代证 |
| AN06-S02 | 伪造财务/身份及属性 | 待消费者与候选 | browser API提交所有server事件；viewer/source/amount/country/IP/secret/任意query；实许可play_session_id；拒绝或按契约过滤且不造成功；play_instance_id与实际许可分离 |
| AN06-S03 | 采集请求边界与去重 | 待消费者与候选 | >20项/>32KiB/未知字段；同UUID同内容重发/同UUID改内容/两次刷新；同事件不加计，变内容拒绝；不把网络请求次数当持久接受数 |
| AN06-F01 | 网络重试与采集故障 | 待消费者与候选 | 仅阻断tracking请求后恢复；模拟存储失败/savepoint回滚；保留业务事务；播放/注册/付款正常；有界短缓冲和稳定UUID；不写重复权益/付款 |
| AN06-F02 | 可靠财务待发 | 待消费者与候选 | Accepted同重放/Skipped无同意与永久无效/Retry临时故障；进程重启；稳定key有界退避，拒绝不永久卡待发，暂未就绪不丢事实；有安全诊断 |
| AN06-M01 | 毛收退款净收 | 待消费者与候选 | BillingAnalyticsReader与真本地账本核300付款/成功退款100→300→纠正0；pending/失败退款；毛收300，净收200→0→300；累计快照不相加，旧fact_version不回滚新版本 |
| AN06-M02 | Reader输入和脱敏 | 待消费者与候选 | 0/200/201原始ID、重复UUID、损坏snapshot、未知付款；05先筛可见ID；先限制原始数量再严格UUID/去重；缺省略，坏snapshot null诊断；无secret/PII |
| AN06-R01 | 同入口7天漏斗 | 待消费者与候选 | A完整/B只有后半/C先后逆序/D跨日/E窗口外/F重复/G观察中；入口期与后续窗分开；每步去重且按时间递进；不拿全站总量相除；分母0为—，观察中单列 |
| AN06-R02 | 资产/角色和聚合隔离 | 待消费者与候选 | Owner/分配投流甲乙/内容/客服/匿名，同过滤器换link/payment范围；仅获授权资产聚合；不可枚举财务/逐人联系方式；测试真实分栏、null不显0 |
| AN06-A01 | 外发关闭时本站仍可用 | 待消费者与候选 | 明确analytics启用并同意，platform disabled/external false；浏览器外网计数+服务端HTTP拦截；采集/报表可用，浏览器/服务端零HTTP尝试；旧测试单不发正式资产 |
| AN06-A02 | Meta/TikTok HTTP替身 | 待消费者与候选 | 固定本机接收端、有效/拒绝/超时/429/5xx/重放/撤回；稳定event_id/去重/预算/诊断，不另发浏览器Purchase；api_accepted≠平台核验 |
| AN06-L01 | 留存和诊断 | 待消费者与候选 | 原始90天/缓冲24h/上限；收件故障/来源缺失/耗尽；后台无secret日志；不删财务、待完成转化、冻结来源；禁用/未知/0分别显示 |
| AN06-X01 | 真实平台与真机 | 外部待配合 | 投流同事账号/像素授权/官方测试事件面板；实际手机完整链路；另取外部及真机证据；本机替身与浏览器模拟不能代证 |
