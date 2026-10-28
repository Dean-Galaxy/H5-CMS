> 2026-10-28更新：今日同一URL正常TLS复核与15+5项透传检查完成后，经00确认已关闭桥89723及通道89724；8408/8409关闭，8406/5906保留。下文“活动窗口接管”属于10-27历史，当前不得把旧URL交付为可用地址。见[10-28证据及关闭回执](evidence/06/paypal-external-20261028/README.md)。

# 06 PayPal 外部回调窗口验收

2026-10-27，接续[本机联合验收](06-PayPal-a82b831本机联合验收.md)。**实际外部HTTPS通道的15项请求、5项字节/头/上游核对通过；官方PayPal验签、付款与退款仍未执行。**

实际应用为`a82b831ba5d03064d7d1564a93a5b201298571ce`；本机冻结交付`172433ab32afd04f7c5250d67e6e98b78370d557`。本轮新增06测试观察器及停止工具，业务、配置、原桥均未修改。[原始脱敏证据](evidence/06/paypal-external-20261027/README.md)逐项列方法与结果。

## 实际执行

本人在00回复“允许，继续 PayPal Sandbox 联调”后，启动独立2026.10.4客户端：Cloudflare→127.0.0.1:8408原桥→实际应用8406。未替换为假站，未读取或应用本人密钥。所有公开路径只允许精确POST `/webhooks/paypal`；后台、媒体、配置、凭据页和metrics访问均拒绝。系统标准TLS校验下，完整格式合成事件实际到达03并返回未配置503，原始正文与五个通知头逐字节摘要一致，额外地区/IP/Origin头未转发，账务九表仍全0。

00已明确接管当前活动窗口用于07登记同App Webhook；按00要求保留原URL与两个进程，由00在本人稍后处理或本次测试结束时关闭。公开报告只保留URL摘要，实际地址在未跟踪0600 `.runtime/paypal-sandbox/external-url.json`。本轮没有声称隧道已经关闭。

仅本次进程：桥89723、通道89724，运行记录在本工作区`.runtime/paypal-sandbox/external-processes.json`。metrics只绑定127.0.0.1:8409。8407仍由00管理；旧8377/5877未动。

## 开启与关闭

本次授权仅限临时回调测试窗口，不包含整站外发或Live。窗口结束执行：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
python3 scripts/ops/paypal_stop_callback_window.py
python3 scripts/ops/paypal_stop_callback_window.py --stop
```

第一行工具仅核对本任务记录的PID、实际命令及进程启动时间；第二行仅向匹配的两个进程发送TERM，不停止网站、数据库、8407或旧站。进程不匹配时不盲目kill。普通网站继续使用8406；其停止仍用`python3 scripts/dev.py stop`。

如00接管当前活动窗口，应持续核对Mac联网/通道进程/URL并明确负责关闭。若已关闭，下次批准窗口会产生新URL，必须更新真实Sandbox App的Webhook地址；不能拿旧URL承诺付款通知可达。

## 后续尚缺

00/07接手本人本机保存材料、受控API-only官方商户/产品核验、同App注册Webhook、实际官方签名和首笔Sandbox付款。06未将合成事件当作官方事件，未启用新购。取消/失败续费/实际退款同步、真机、实际邮件与可信地区、Linux部署/重启/本候选独立恢复仍需分项执行。历史恢复报告不会替代新版本恢复证据。
