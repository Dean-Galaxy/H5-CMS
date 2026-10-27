# 06 PayPal Sandbox 独立环境与 HTTPS 回调方案

2026-10-27。状态：**本机准备已验证；新应用、官方支付和公网入口尚未验收。** 本文不授权开启隧道、部署、采购或 Live。00先审核方案、固定完整组合，必要对外动作再交本人批准。

## 1. 当前实际结果与版本

| 项目 | 本轮事实 |
|---|---|
| 独立工作区 / 分支 | `h5-06-paypal-sandbox/H5` / `codex/06-paypal-sandbox` |
| 创建起点 | `96a1a158835678531fbf3ee8135796d94b59dc33`，仅准备基线，不是最终PayPal候选 |
| 00已指定公共补丁 | `fc81e576d1abb813c24d125bc0ddc8238b1f574d`，正常快进合入 |
| 本轮实测工具版本 | `272b2ac1ae7b1d3fdb8a990ec3c20a5f810165e7`；后续本包只加文档/脱敏证据 |
| 契约 | 业务1.0.5，公共配置附录 `docs/contracts/paypal-sandbox-v1.md` |
| 独立配置 | `scripts/dev.py setup --port 8406 --db-port 5906` 实际成功；workspace / DB预留名 `h5_0a65842d38`，测试库将为同名加 `_test` |
| 未运行 | 没有启动PG、迁移、安装应用依赖或应用服务；8406/5906未监听，未创建PG目录。没有刷新任何旧库 |
| 本机录入工具 | 实际仅绑定 `127.0.0.1:8407`；13项HTTP/文件边界测试，实际Chrome 156的10项检查通过 |
| 测试后状态 | 8407已关闭，本次合成凭据已删除；没有本人凭据、没有PayPal请求、没有公网服务 |
| 保留对象 | 周四8377/5877及其账号、素材、配置未操作；不从他人工作区搬运数据或env |

证据：[索引](evidence/06/paypal-preparation/README.md)、[浏览器回执](evidence/06/paypal-preparation/browser.json)、[测试回执](evidence/06/paypal-preparation/unit-tests.txt)、[版本与停机检查](evidence/06/paypal-preparation/receipt.json)。不以00公共补丁的14项/365断言冒充本任务重跑；不以旧373/5677或旧HLS/恢复报告证明本候选通过。

接手时已核对：历史 `c676597` 属于be7bb4f；此后F09、二维码修复及10月26日备份已有新交付，不能退回旧8206/8303。主线AGENTS确认07周四12/12已完成，06旧交接页的0/12是历史。10月26日备份交付见 `handoffs/06-backup-audit-2026-10-26.md`，本轮没有新执行恢复，也不把代码GitHub备份称为完整数据恢复。

## 2. 本人怎么私密录入、验证和关闭

当前页面已关闭。07开始指导时，在此Mac的终端依次执行：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
python3 scripts/ops/paypal_sandbox_credentials.py
```

1. 在这台Mac打开 `http://127.0.0.1:8407/`。这是凭据录入页，不是观众网站、不是另一台手机入口。
2. 从PayPal Developer的 **Sandbox** App页复制 Client ID / Secret。填写固定商户别名，例如 `h5_us_sandbox`；卖家ID、产品ID、真实注册Webhook ID尚未取得时可留空，后补时需完整重填。
3. 点击保存。只会显示固定成功/失败文字，已有值不回显；不要截图已填写页面，不发聊天、不粘贴终端参数。保存后自行清空剪贴板。
4. 成功仅表示离线结构校验与本机落盘，不证明凭据属于Sandbox、商户匹配、官方权限或支付成功。Live与Sandbox凭据不能靠字符串形状区分；最终由固定Sandbox端点和03官方核对确认。
5. 成功后在终端按 Control+C；或重新打开首页点击“关闭录入服务”。停服务保留私密文件。端口被占用时工具拒绝启动，不停止别人服务。

文件位于此工作区 `.runtime/paypal-sandbox/credentials.json`，目录0700、文件0600、临时文件原子替换；`.runtime`已被Git忽略。它是本机未加密私密文件，保护边界是本机账号权限，不是密钥保险库。工具无外部脚本、网络SDK或读取文件路由；精确Host/Origin与随机CSRF拒绝跨站提交，拒绝符号链接目录/目标、未知字段、重复字段和超长正文。访问日志关闭，表单/响应禁止缓存，不将请求值放进错误文字。

**不会自动改 `.env` 或选择付款渠道。** 等00固定完整组合后，06在本工作区按附录逐字段映射至 `H5_BILLING_PAYPAL_SANDBOX_{CLIENT_ID,CLIENT_SECRET,MERCHANT_ID,MERCHANT_SCOPE,PRODUCT_ID,WEBHOOK_ID}`，保持 `ENABLED=false` / `NEW_PURCHASE_PROVIDER=unconfigured`，先执行03已接收的安全诊断/产品准备入口。缺回调ID不可做真实验签；只有正式候选、实际账号范围核验和回调就绪后，按00串行窗口开启Sandbox。不得用猜测的命令名或空壳成功替代03运行入口。

## 3. 两种公网方案及推荐

**当前契约兼容方案是受控服务器直连TLS。临时隧道可省资源，但当前不能直接实施。** 现有Guard要求staging、受控开关、真实HTTPS源站、无可信代理、本机模拟关闭、SMTP强制TLS及安全会话；地区来源也不能伪造。域名看起来是HTTPS不等于满足这些条件。

| 项目 | A：临时回调专用隧道，待公共适配 | B：受控staging服务器直连TLS，推荐评审基线 |
|---|---|---|
| 数据位置 | 新06独立库/文件；绝不指向8377 | 新服务器专属PG/媒体/密钥，不能与本机独立库各自维护同一测试订阅 |
| 入口 | Cloudflare Quick Tunnel → 专用默认拒绝的入口 → 00批准的staging接收链 | DNS直接解析服务器 → Nginx TLS443 → Unix socket PHP-FPM → 唯一PG |
| 公开范围 | 仅精确Webhook和静态return/cancel；不得把8406整站或8407凭据页接隧道 | 同样仅Webhook和静态return/cancel公开；操作员UI需批准固定来源或私网通道，并保留应用认证/MFA |
| 主要前置 | 00/03冻结可审计的代理/TLS/原始正文/来源处理契约并通过拒绝测试，不能改Host或伪造HTTPS绕过现有Guard | 域名、DNS权限、服务器授权、TLS证书、SMTP、可信地区适配、候选依赖审计与03真实处理器 |
| 费用与账号 | 本轮本机准备新增采购0；Quick Tunnel无需账号/域名，临时服务可用性与采购条件在批准窗口复核 | 低流量2GiB/1vCPU/50GiB服务器参考12美元/月；域名及超额/税费另算，见下节 |
| 限制 | URL每次变化，无可用性保证；Mac休眠或进程停止即断线；更换URL须更新Webhook并绑定实际ID | 可持续接收迟到/重试通知；需要维护备份、补丁、进程和监控 |
| 当前结果 | 未安装/开启隧道、未建立受控入口 | 未采购、未部署、未做Linux/nginx/TLS或重启实测 |

Cloudflare官方说明临时URL无需账号/域名、停止进程即失效且没有可用性保证；邮件PIN是交互式验证，不适合PayPal机器通知。因此Webhook不能套浏览器登录挑战。[Quick Tunnels](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/)

### 精确暴露清单（拟议，尚未部署）

| 公网请求 | 拟议处理 |
|---|---|
| `POST /webhooks/paypal`，无查询参数 | 唯一应用处理器；限制正文2MiB、连接/上游超时；原始字节不解析重编码；只透传必要类型/长度及下列五个PayPal头；03官方验签和可靠收件成功后才2xx |
| `GET /sandbox/paypal/return` | 拟议静态页：“已返回，请回原测试窗口查询；不代表已付款”。不回显参数、不置会员、不自动跨域跳转、不收cookie |
| `GET /sandbox/paypal/cancel` | 拟议静态页：“已离开审批，请回原测试窗口确认状态”。浏览器离开不等于平台订阅已取消 |
| 其他方法/路径，包括 `/admin`、`/livewire`、`/api`、媒体、`.env`、备份、8407配置入口 | 公网默认404/405；不能采用通配转发。操作员私有访问另有来源控制并仍由应用验证会话/CSRF/MFA |

静态回跳路径必须先由03/04确认接线；未确认前不能拿这些路径注册官方App。B方案操作员访问与Webhook必须落到**同一staging应用/库**，不能让本机产生订单、另一个空服务器接收通知而宣称完成闭环。

五个必需头：`PAYPAL-AUTH-ALGO`、`PAYPAL-CERT-URL`、`PAYPAL-TRANSMISSION-ID`、`PAYPAL-TRANSMISSION-SIG`、`PAYPAL-TRANSMISSION-TIME`。06只读发现现有 `deploy/nginx-staging.conf.template` 禁止自动转发请求头但尚未包含这五项；后续06需补模板并以真实Nginx→FPM证明到达03。当前不能称现有部署模板已支持PayPal验签。

认证依据是03固定Sandbox API的官方验签、Webhook ID、App/商户/环境范围和原对象核对。随机路径、浏览器回跳、2xx或ACTIVE均不能代替。不能信任客户端地区头、任意证书/API URL；不在边缘请求捕获产品里保留支付正文。关闭原始访问日志、URL查询、认证头、正文记录；保留脱敏事件关联、结果码、耗时和重试计数，告警仅报失败种类和内部关联号。入口限流应返回非2xx让官方重试，禁止过载时假确认。

PayPal要求HTTPS443，注册URL得到实际Webhook ID；失败通知最多重试25次、持续3天。模拟器与真实App事件不同，不能用于证明官方支付。[官方Webhook接入](https://developer.paypal.com/api/rest/webhooks/rest/)

## 4. 最小资源与费用依据（非采购指令）

| 资源 | 最小建议 / 用途 | 预算与本人需要准备 |
|---|---|---|
| 服务器 | 低流量Sandbox使用2GiB内存/1vCPU/50GiB SSD，Nginx、PHP8.4、PG17、队列与定时独立进程；不并行转码，不作为正式容量结论 | DigitalOcean Regular参考12美元/月、0.01786美元/小时；需要云账号/支付方式和明确预算。按实际区域库存/税费/出站计价复核后才采购 |
| 数据库 | 同机PG仅loopback/Unix socket，独立普通/测试库，最小权限用户，不开放5432 | 包含在上项自管资源内；不是托管备份或高可用 |
| 视频 | 新环境自生成的短小合成测试片，置私有目录，签名路由访问；不搬运旧手测资产 | 首次Sandbox无新增视频服务账号/费用；真实片库和CDN预算另定，不声称性能已足够 |
| 域名/TLS | 现有可控域名新增专用staging子域；ACME证书；DNS直接到源站 | 已有域名可无新增注册费用；若无域名，先选名称/后缀并取得实际报价，不猜统一年费 |
| 邮件 | 经批准SMTP+TLS、已验证发件域名，验证注册/重置的真实送达与退信 | Brevo Free公开额度300封/日；可作为0美元小量候选，需账号实际开通SMTP及DNS验证，不保证获批或可达 |
| 备份 | 加密逻辑备份与媒体清单，另一个独立恢复库验证；付款事实备份和代码备份分开 | 本机/已有存储可用于试验；异地存储容量、保留时间与费用另报，不能把单机磁盘当异地备份 |

价格核对日2026-10-27：[DigitalOcean官方价格](https://www.digitalocean.com/pricing/droplets)、[Brevo官方计划说明](https://help.brevo.com/hc/en-us/articles/208589409-About-Brevo-s-pricing-plans)。在已有域名和获准免费SMTP的条件下，B方案服务器基础预算约12美元/月，**不含域名新购、税费、超额流量、异地备份**。本轮实际采购0。账号由本人办理，不替公司给出地区/商户合规结论。

## 5. 批准后的串行执行顺序与官方事件

1. 00交付完整不可变候选SHA（公共配置、依赖补丁、03路由/绑定/迁移/原对象核验、04呈现）。06核对应用树和锁文件，新环境安装依赖、空库迁移、契约、组合与未配置拒绝测试，记录实际SHA；先不接真实凭据。
2. 本人经07在私密本机入口填写Sandbox App材料。06只查看字段是否齐备/脱敏诊断，不读取到聊天。通过03正式入口确认固定Sandbox OAuth、卖家范围、产品；不得将Client ID/Secret设置在浏览器JS中。
3. 00明确A或B的可信入口、地区和邮件条件，用户批准精确外网窗口；06部署并实际验证TLS、404/405拒绝、五个签名头、原始正文和错误重试。只有真实URL存在后才配置Webhook；不把localhost填入PayPal。
4. 在同一个Sandbox App注册精确URL及下面清单，把实际Webhook ID私密回填。03接收候选必须逐项确认支持事件；未实现的事件不能标为覆盖。不要选择全事件代替处理范围核对。
5. 07指导本人用Personal Sandbox买家登录官方审批页，商户为独立Business卖家。由03准备新环境独立、用户确认的测试价格；禁止抄旧工作区价格。记录服务器offer金额/币种、原订阅、实际sale事实、有效签名入箱、订单与权益、回到网站会员播放；ID只在私密证据，公开报告用映射号。
6. 验取消未来续费保留本期、失败续费、部分/整笔实际Sandbox退款、重复/乱序/迟到事件、对象越权、旧链接未知状态和价格版本隔离。实际周期不能加速/制造成功时明确待验证；恢复续费在官方原价原时间且不立即扣款证据齐备前保持关闭。
7. 核对无05正式资产外发、无Live配置；实际通知、媒体、邮件各自取证。系统重启后核对任务和队列恢复、无重复发布、无重复入账；真机另记录型号/系统/网络与操作，不拿Chrome模拟代替。

拟注册的官方订阅事件：

```text
PAYMENT.SALE.COMPLETED
PAYMENT.SALE.REFUNDED
PAYMENT.SALE.REVERSED
BILLING.SUBSCRIPTION.CREATED
BILLING.SUBSCRIPTION.ACTIVATED
BILLING.SUBSCRIPTION.UPDATED
BILLING.SUBSCRIPTION.SUSPENDED
BILLING.SUBSCRIPTION.CANCELLED
BILLING.SUBSCRIPTION.EXPIRED
BILLING.SUBSCRIPTION.PAYMENT.FAILED
```

来源：[PayPal订阅Webhook事件](https://developer.paypal.com/subscriptions/webhooks)。这是对接清单，不是已经注册/收到的回执；`PAYMENT.CAPTURE.COMPLETED`不能冒充Subscriptions Sale事件。

## 6. 停止、回收与不覆盖支付事实的回退

- 临时结束先关闭新购入口，核对所有待处理订单/订阅、退款与队列；保持可靠收件与对账到状态可解释。不要先删除数据库或注销Webhook造成无法追踪的迟到款。
- A在核对完成后停止本次隧道进程，实际从外部检查URL不可达；移除本次App的Webhook注册/回跳配置，保留必要脱敏回执。若测试还需迟到重试，提前批准继续时段或转稳定入口，不能假设Mac离线无影响。
- B先停新购，保留收件/对账，确认无遗漏后停服务、撤回本次DNS/防火墙开放、注销本次Webhook；资源删除另需明确授权。先保存备份，不能为省费用直接删除。
- 凭据用完按本人决定撤销/轮换本次App秘密、撤销本次隧道token；本机`.runtime`只定向删除本次文件，不能批量删除其他工作区配置。
- 应用回退只回代码到与现有schema兼容的版本；任何官方通知进入后都不把数据库恢复成旧快照覆盖新支付事实。不执行已采用迁移的down。先保留原收件箱/事件和账本，停止不兼容写入，由03完成前向修复与官方对账。
- 正式恢复演练在独立新库/PG端口，禁止同时跑两个消费同一商户事件的写入者；恢复进程初始禁外发/新购/自动发布，再核对订单、付款、退款、权益、发布状态、密钥解密与文件摘要。将备份后新增支付事实通过官方核对和幂等导入补齐，而非重放浏览器回跳。

本轮只有历史备份审阅与工具准备；**未执行新支付库备份、恢复、服务器重启或回退**。后续每项都要写真实时间、源/目标版本、对象清单与首个失败环节。

## 7. 00本次接收项与未就绪项

- 可接收：06独立配置已生成；私密录入工具及13+10项实际验证；本方案和脱敏证据。没有业务模块、公共根配置或迁移改动。
- 待00：固定完整候选、公共依赖公告处理；决定直接staging还是审查隧道新可信入口；协调可信地区、SMTP和单一部署窗口，外部动作具体后征求本人批准。
- 待03/04：运行闭环/诊断产品入口、静态回跳与购买页面接线、十种事件实际支持、首笔官方Sale到账/退款/取消可复验；不能靠internal mock结论放行。
- 本人已完成的美国Business / REST Sandbox App / 买卖测试账号由07记录，不重复要求申请；真实凭据只在本机填写。尚无服务器/域名/邮件采购授权，不先买后报。
- 发现的入口头缺项交00纳入部署接收；后续业务故障只给复现、影响、版本和首个失败环节，回原模块修复，06接新候选回归。
