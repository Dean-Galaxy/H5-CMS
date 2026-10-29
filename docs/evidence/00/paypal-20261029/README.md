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
