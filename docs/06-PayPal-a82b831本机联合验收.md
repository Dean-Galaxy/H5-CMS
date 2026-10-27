# 06 PayPal a82b831 本机联合验收

2026-10-27。**本机候选、观众入口与回调桥拒绝边界通过本报告所列检查；没有官方付款或公网验收通过结论。** 先交本机固定包，随后按本人已明确批准的仅回调外网窗口继续。本人批准原话已由06读取00对话确认，不把总控转述单独当作授权。

## 版本与边界

| 项目 | 实际版本 / 结果 |
|---|---|
| 00统一应用候选 | `a82b831ba5d03064d7d1564a93a5b201298571ce`，含03/04/共享配置及Livewire4.4.8 |
| 迁移与定向组合checkout | `a3a5d0ade408576233893a79b9d15c6c87ddc042`；仅附加06夹具/ops |
| 修正后HTTP、Chrome、工具checkout | `9364edc61616179567090e76560527c23aabf0cf` |
| 业务源码相同性 | app/bootstrap/config/database/resources/routes/public及Composer文件的Git对象均与a82b831相同，见manifest |
| 06业务修改 | 无；仅自有Stripe模拟夹具显式选stripe、预览夹具增加session version和未配置保护、验收/运维工具 |
| 运行归属 | 新 `h5-06-paypal-sandbox/H5`，网站8406、PG5906，`h5_0a65842d38` / `_test`；没有复制旧env/账号/素材 |
| 当前真实状态 | web/queue/scheduler/PG/health均正常；8407由00为07保留；8408/8409关闭，无隧道 |
| 本机条件 | 明确local合成US地区、preview邮件；PayPal关闭、新购unconfigured、05未启用 |

[证据索引](evidence/06/paypal-a82b831/README.md)和[版本/对象摘要](evidence/06/paypal-a82b831/manifest.json)是本轮依据。不能拿旧c676597、373/5677、F09恢复或00的362项替本报告未执行项背书。

## 已执行

| 范围 | 实际结果 | 证据边界 |
|---|---|---|
| 全新安装 / 迁移 | 独立Composer锁定安装、18迁移完成、5类进程/健康正常 | 不是Linux/systemd/外部部署 |
| 契约 / 依赖 | 1.0.5/59样例；锁定审计0 advisory、0 abandoned；hls.js1.7.3摘要检查通过 | 公告查询时点2026-10-27，不等于长期无风险 |
| 定向组合 | 44项 / 787断言：Integration、Security、PayPalRuntime、BillingUi | 支付事件为隔离测试transport/FakeGateway；不是官方 |
| 本机真实HTTP注册 | 5组通过：OpenAPI响应、CSRF、观众/员工隔离、preview验证与链接重放拒绝、退出/重登/受控return | 新合成观众、真实HTTP；邮件未外发 |
| 回调/购买HTTP | 修正后的25项通过；Cookie-free机器通知实际经过桥到03，应用未配置503；购买API无CSRF419、合法输入503 | 非已配置App的真实错误签名验证，不声称已通过官方验签 |
| Chrome | 11项通过：首页、试看、实际MSE解码、签名清单/TS200、无签名403、第6集会员拒绝无媒体请求、测试价、合成登录、购买页面503、无外部请求/脚本错误 | 明确强制非原生HLS分支，实际27帧/readyState4；不是自然设备兼容/真机 |
| 06工具 | 48项0失败/错误/跳过，用本工作区实际安装的dotenv库 | 仅临时合成文件/上游；未读取或应用本人暂存值 |
| 账务不变 | 订单、订阅、付款、退款、权益、inbox、outbox、provider intents、PayPal observations共9表前后全0 | 报价可生成，不产生假付款/假会员 |
| 回调客户端 | 官方cloudflared2026.10.4 macOS ARM64独立下载，官方SHA256/大小匹配，version/help验证 | 没开隧道；不修改全局2025.5.0 |

本轮没有新执行备份恢复、服务器重启或官方续费/取消/退款；旧备份和恢复仅保留对应版本历史，不把本轮逻辑RecoveryScenario测试叫“真实恢复”。

## 已发现并纠正的06验收问题

1. **Cookie污染导致联合证明失效**：旧脚本共用CookieJar，同127.0.0.1跨端口携带Laravel Cookie；桥提前403，原24项回执不能证明到03。00指出后，06保留原回执为`joint-http-superseded-cookie.json`，不作为当前通过依据。
2. 修正：桥使用无Cookie opener；带Cookie403单独检验，缺五头400且零上游；完整格式合成事件直达应用503、经桥503。测试仅临时包装真实HTTPConnection，记录实际收到 `127.0.0.1:8406 POST /webhooks/paypal → 503` 一次，证明不是桥连接失败自行生成503；不改桥产品代码、不记录正文或签名值。
3. 首次探针把不存在的相邻路径预期为419，实际404；更正为“路由不存在”，浏览器购买POST的419另作真实CSRF证据。首次Chrome价格断言期待美元符号，实际批准界面显示`USD 3.00`/`USD 12.00`；按真实显示核对后全11项通过。这两项属于验收脚本错误，未改业务来迎合测试。

旧回执/失败说明与最新回执分开保留。没有发现需要本轮回业务模块修复的已证实新问题。

## 产品经理如何打开和使用

- 这台Mac打开 [观众首页](http://127.0.0.1:8406/) 或 [合成测试剧](http://127.0.0.1:8406/dramas/01a17c99-e015-701a-aac6-2817c9e29e89)。6个分集复用本任务新生成的140秒测试图案片，前5集免费，第6集明确要求会员。不是采购片库。
- 打开 [会员测试页](http://127.0.0.1:8406/membership?purchase=1)，首周USD3.00、常规周价USD12.00，数据库is_test=true且页面有测试提示。**当前未启用付款，点击购买应提示未就绪，不会跳官方扣款。**
- 已注册并通过本机preview验证的合成观众凭据在此工作区 `.runtime/http-smoke-account.json`，仅本人/07在本机查看，不发聊天或入Git。也可自行注册新观众，再从本工作区邮件预览完成验证；不是实际邮件送达。
- 本人PayPal材料仍由07在8407私密页指导，06没有读取、覆盖或应用该文件；新的ID补齐无需重填Client Secret，操作见[配置闭环](06-PayPal仅回调桥与配置闭环.md)。
- 上述127.0.0.1地址仅这台Mac可用，不能当成别人手机的入口。没有声称真实设备检查完成。

需要查看状态或停止时，只在新工作区执行：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
python3 scripts/dev.py status
python3 scripts/dev.py stop
```

重新开启用`python3 scripts/dev.py start`，保留账号/素材/数据库。此停止只管本工作区web/queue/scheduler/PG，不停止8407录入服务或其他工作区；8407在其页面关闭或由启动者单独结束。07使用期间不跑RefreshDatabase/seed或覆盖夹具。拔外置盘/停止Mac网络前让00确认是否有后续回调窗口。

## 继续联调的准确条件

本人已在00明确批准临时Cloudflare仅 `/webhooks/paypal` 外网窗口；06先固定本报告，再执行真实HTTPS路径/头/正文透传验证。**批准不是执行回执**，本报告仍标“外网未执行”。实际命令使用独立`.runtime/tools/cloudflared-2026.10.4`，已删除新版不存在的`--transport-loglevel`；详情见配置闭环。

仍需00/07衔接：本人私密材料保存回执→固定候选停止/应用仍off→明确API-only准备窗口→官方商户/产品核验→真实注册Webhook ID→官方事件验签与唯一账本→明确启用Sandbox新购→本人Personal买家完成首笔。六字段齐备不等于这些步骤已完成。全程不启Live，不代本人处理公司认证或采购。

取消/失败续费、部分与整笔真实退款、迟到与乱序、实际支付后权益/媒体、持续收件、重启与独立恢复、真机完整链路仍需分别取证；官方Stripe尚未验收，恢复续费F01仍按约定关闭。真实邮件/地区及整站公开部署不由本次仅回调批准自动放行。
