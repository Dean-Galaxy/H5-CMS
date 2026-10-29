# 00 并行 Sandbox 入口接收证据

实际06运行候选ca279acad01793203c7976154acca9fd3e71c248；接收文档和新增测试不要求更新运行候选。

- upgrade-independent-safe.json：00独立比较06私密前后摘要，13账务、45受保护业务表均保持；运行后sessions变化单列，不宣称所有表完全不变。
- config-diff-safe.json：00接管后仅修改当前Webhook URL、API、新购三个设置；凭据、工作区及数据库密码摘要另实际比对保持，42媒体内容摘要保持。
- entry-safe.json：开启新购后本地只读检查；原SUSPENDED来自本地已保存状态，不冒称本文件作了官方订阅读取；原付费区间、退款0和进度保持。B未注册，现价300/1200美分USD测试。
- official-readiness-safe.json：现有Webhook URL PATCH200/GET200，同ID及十项事件；随后官方只读准入通过，不创建新订阅，不代表B付款通过。原始ID/URL/认证及响应留私密目录。
- enabled-window-boundaries-safe.json：开启API后四项公网拒绝边界，正常CA/域名TLS；06完整15+5传输见其同日证据。
- browser-first-safe.json：初测错误要求匿名会员页显示PayPal品牌，实际模板无此要求，7过1失败；没有修改UI。
- browser-safe.json：改为真实游客登录门槛和无直接付款表单，Chrome9项通过；不注册、验证或替本人付款，也没有实际进入PayPal收银台。
- billing-runtime-tests.txt：00在main集成后独立5541的_test运行54项1314断言，通过；只包含HTTP Mock，不是官方续扣/到期。

临时窗口现交07指导本人，00负责暂停/结束收尾。当前没有设置未来自动监控或提醒；原A到期、新B续费、C退款、D恢复分别等待其实际证据。GitHub成功以远程回读回执为准。

## B首款与后续工程追加

- b-first-payment-safe.json：本人反馈后00实际官方只读核B首款、Plan、本地唯一付款/权益/outbox及真实Sale签名收件，A仍官方SUSPENDED且区间保持。具体对象、日期及下一次时间私密登记，自动续扣未发生。
- b-data-backup-safe.json：B首款后新增归档可读，不是完整恢复演练。
- refund-tests.txt：00独立_test的C两项新回归及原部分/全额退款，3项173断言通过；Mock，不算官方退款。
- replay-observer-summary-safe.json及replay-observer-tests.txt：00系统Python独立61项、0skip。工具已接收未部署，当前窗口不变；未实际重送或观察第二次官方通知。首次汇总脚本误把测试名中的skipped当skip，修汇总后最终完整61项通过。

当前最新交接见[00-38](../../../handoffs/00-38-B首款核验与C退款准备.md)。

## C首款与付款等待提示追加

- c-first-payment-safe.json：00实际只读核同一C官方3USD首款、周Plan、本地唯一付款/权益/outbox及真实签名收件，A/B官方状态保留；当前不代表退款或B续扣通过。
- c-before-refund-backup-safe.json：退款前新增归档133372字节可读、A/B/C前置私密快照及准确本机交易核对单；实际ID/日期/账号不在本文件，00没有代退。
- payment-wait-hint-tests.txt：00集成后22项273断言通过；条件显示/中英文案与既有PayPal重查规则，HTTP Mock，不算新官方付款。04样例窄屏截图由00只读复看，新文案仍未部署到实际06。

最新交接见[00-39](../../../handoffs/00-39-C首款核验与付款等待提示.md)。

## C部分退款恢复与实际接收

00-40保留旧C待核失败及工程红绿证据；实际候选21f33在GitHub回读后保数据接入，06现场证据另见同日paypal-refund-deploy目录。

- c-targeted-recovery-safe.json：原C完整官方事实核准后正常清待核，再两次退款读；不新增原有2条取消，不创建退款。
- c-two-reconciliation-cycles-safe.json：已预检仅3个固定未到期/无pending对象后，两轮完整真实对账均0、计数保持。
- refund-fix-api-on-scheduler-safe.json：明确两轮恢复后实际调度；仅这些轮次随后被C1观察覆盖。
- c-partial-verified-safe.json：对应21f33，在上述实际调度后再次官方只读C1通过，A/B核心保持，诊断时间排除规则明示。
- refund-fix-official-readiness-safe.json及refund-fix-live-boundaries-safe.json：无新资源的官方准入、正常TLS拒绝边界和实际源码匹配；不代表新购/额外官方重复通知通过。
- c-partial-verified-backup-safe.json：新136811字节归档可读、第二步准确本机定位资料；非完整恢复，不含原始对象/日期。

实际C1接收及下一步见[00-41](../../../handoffs/00-41-C部分退款修复后实际验收与第二步放行.md)。C2尚待本人操作/真实退款结果，不能将其提前计入。
