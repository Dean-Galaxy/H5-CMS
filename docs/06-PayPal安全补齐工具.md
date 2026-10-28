# 06 PayPal 安全补齐工具

2026-10-28。仅06工具与合成测试，未合入新业务候选至实际运行，未读取本人密钥、修改实际env或生成本人核对回执。当前临时公网窗口保持关闭。

## 接收与验证状态

- URL以00固定共享补丁`ef321908e53965e79f2c4f3f5bca32f17ded00ac`为配置接口，旧六字段schema 1不变；实际运行候选尚无此字段时工具直接拒绝。
- 商户以03本日给出的回执schema为接口，读取03 `billing:paypal-prepare --binding-context`，不自行冒充模块上下文。该命令必须支持API off且Merchant ID为空；06发现旧实现仍要求API on，已报00/03修正。
- 18项URL合成测试、13项商户合成测试通过，使用临时Git工作区和本工作区实际dotenv库。商户CLI输出目前为合成适配；待03固定提交后做真正CLI接线检查，不能把本组测试叫官方绑定/付款通过。

## URL单独补齐

工具：`scripts/ops/paypal_complete_webhook_url.py`。只读取本任务0600的`external-url.json`、`external-processes.json`、随机合成marker；不读取或改动`credentials.json`，也不接收任意URL参数。

实际URL必须是本次获批Quick Tunnel origin，工具只拼一次精确`/webhooks/paypal`。拒绝其他主机、明文HTTP、其他端口、路径、用户信息、query/fragment。当前客户端固定2026.10.4、桥8408、指标8409、实际应用端口取自身workspace；完整隧道命令必须一致，没有额外--url覆盖，PID和进程启动时间必须匹配。未来其他托管方式需另接来源证据，不能绕过此限制。

运行前：00接收精确候选、API关闭、新购unconfigured、web/queue/scheduler停止、配置缓存清理、工作区数据库/会话/队列绑定正确。默认只检查，`--apply`才改唯一`H5_BILLING_PAYPAL_SANDBOX_WEBHOOK_URL`字段；同一apply锁、0600备份、原子替换，前后再次校验候选和进程。若写后窗口/配置改变，明确报告已写但核验失败，不盲目回滚覆盖并发变更。其余env字段、Client ID/Secret原样保留。

```sh
# 仅在00安排新窗口并接收候选后，由00使用真实完整HEAD替换候选占位。
python3 scripts/ops/paypal_complete_webhook_url.py --candidate <00接受的完整HEAD>
python3 scripts/ops/paypal_complete_webhook_url.py --candidate <00接受的完整HEAD> --apply
```

活动进程只是本机窗口归属证据，不能替代外部TLS可达、官方Webhook注册读回或通知验签。工具不请求该地址，03 `--check-webhook`须固定官方API读回ID/URL严格匹配。停机后不能拿已失效旧URL补齐。

## 本人一次商户录入

工具：`scripts/ops/paypal_merchant_receipt.py --candidate <00接受的完整HEAD>`。只在本人实际TTY操作，不支持管道自动回答；不重开8407旧覆盖式表单。

1. 00先让已接受03候选的离线context可用，停应用、API off；原Client ID/Secret/scope已经安全应用。
2. 工具私密核对源六字段与已应用env中的Client ID、Secret、scope完全一致，再核对03返回的scope、Client指纹和已应用Merchant ID。旧Merchant ID仅可空或相同。
3. 本人输入一次Merchant/Account ID，不回显；不提示重输Client ID或Secret。
4. 本人逐项输入`yes`：已核对同一Sandbox Merchant App关联的Business；已核对该Business详情Merchant ID；已核对本机保存的Client ID属于同一个App。空白、其他输入或取消即结束，不修改、无回执，不预选true。
5. 只有三项全确认，工具持有共享锁并再次检查原身份未变，补源文件空Merchant ID并保存0600回执。回执含03精确字段、实际UTC时间与不含个人资料的随机evidence_ref。不会改env、绑定数据库或执行官方API。
6. 00安全apply该待应用ID（仍off），随后安排官方API-only窗口，让03 `--bind-merchant=/本工作区/.runtime/paypal-sandbox/merchant-dashboard-….json`验证完全相同的上下文、证据时间与文件安全，并执行强制新OAuth。`--check-merchant`、`--check-webhook`继续按03真实返回记录。

如果stage写入后遇故障，工具删除未完成receipt并明确“ID可能已暂存、无有效回执”，不称完成。重试只允许相同ID；任何不同Client/Secret/scope、并发改动或重绑均拒绝。本人未实际核对时，不得由00/06预生成三个true。

## 结论边界

这是一份来源为operator_dashboard的人工初始化回执，不是API自动识别商户。最终应分别记录merchant_binding_valid、binding_source、api_merchant_identity_verified=false、app_authenticated、webhook_registration_valid；OAuth成功、产品读权限都不能证明订阅资格、实际付款或对应权益。官方绑定与新购仍由00串行安排。
