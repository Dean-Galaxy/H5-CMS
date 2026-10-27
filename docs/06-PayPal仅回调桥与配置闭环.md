# 06 PayPal：仅回调桥与配置闭环

2026-10-27追加。00已在 `a57fd2e` 接收回调专用工程约定，06已正常合入；**不是本人对外网启动的批准**。本方案取代早先“首笔Sandbox必须先有完整staging服务器”的建议；整站staging仍遵守原TLS、邮件、地区及部署约定。本机网站不对外，不把local标成公网测试站。

## 当前实现和证据

- 凭据录入工具：已交 `39c8c6d`，00独立13项通过并启动8407交07指导。06后续没有读取、覆盖或删除本人可能已填的文件，没有重启该页面。
- 回调桥：`c4b1ff0`初版16项由00独立通过；`5903b34`追加每分钟60次全局上游预算和第17项。所有测试是临时回环合成上游，不是官方验签。
- 配置应用与补ID：最终工具 `3699a32b73ca20e16aa3aa488e99d202e414751e`，18项合成临时工作区测试；真实vlucas/phpdotenv解析器证明所有可打印特殊字符按字面保留。没有启动Laravel、联网或应用本人配置。
- 桥17 + 应用18 + 录入13 = 48项工具检查。最终固定版本与实际运行回执见 [证据](evidence/06/paypal-bridge/README.md)；Chrome10项仍属于 `272b2ac`，本次没有把它改标成新版本。
- 当前应用代码虽已包含00接收的03基础段，完整03/04联合候选尚待00指定；8406/5906未启动或迁移。旧8377/5877不动。

## 1. 首笔Sandbox最小方案

```text
PayPal Sandbox → 临时HTTPS443 → Cloudflare → 127.0.0.1:8408回调桥
                                                   ↓ 唯一固定路径
                                          127.0.0.1:8406/webhooks/paypal
                                                   ↓
                                         03官方验签 → 可靠收件 → 核对/账本
本人浏览器 → 127.0.0.1:8406观众页 → 官方Sandbox审批 → 返回本人本机
```

只有 `POST /webhooks/paypal` 经过外网；无查询参数、前后缀、编码/双斜杠变体。**不开放任何return/cancel页面**，回跳按03/04实际本机路由；不开放8407凭据页、后台、网站、媒体、数据库。PayPal实际创建订阅若拒绝本机回跳URL，记录首个失败并交03/00，不擅自添加公共页面或伪造HTTPS。

桥只绑定127.0.0.1。上游地址从本工作区`workspace.json`取得并核对根路径，固定为本次8406及上述路径；没有任意URL或上游Host参数。传入的Cookie/Authorization/Proxy-Authorization直接拒绝；IP/地区/Proto/Origin及任意额外头都不转发。Cloudflare可能附加代理头，因此是**丢弃代理头**，不是据其建立信任。Host的两次固定改写只表示内部路由：隧道→8408，桥→8406，不声称浏览器来自本地或应用请求使用HTTPS。

只传五个PayPal验签头与Content-Type/Length，原始正文不解析、不重编码。正文1–262144字节、JSON媒体类型、关键头恰好一个；拒压缩、chunked、Expect、Upgrade。4个并发、20秒总期限、10秒上游超时、60次/分钟全局转发预算。超额429/503，失败保持非2xx供官方重试；不可信IP不能绕限流。上游3xx变502且不跟随，回复只包含状态和空正文，绝不转发Set-Cookie、Location、业务错误或认证信息。

桥没有实现自己的PayPal验签或账本。即使合成上游返回200，也只能证明状态转发；真正03处理器必须证明签名未配置/错误不能2xx、有效验签且可靠入箱后才确认，权益再依据官方Sale与周期核对。回环接收者IP会成为桥的本机IP，不能拿它当买家IP/地区/广告归因。

费用：Cloudflare Quick Tunnels官方说明免费、无需账号/域名，适合短时测试。此方案不采购服务器/域名/SMTP；Mac和现有网络由本人提供。免费不等于稳定服务：URL变更、Mac睡眠或停进程都会中断，不能承担正式收费或周级续费持续验收。[官方免费说明](https://blog.cloudflare.com/protected-quick-tunnels)、[临时服务限制](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/)

## 2. 现有Guard的实际审查

- 本工作区已合公共 `a57fd2e`：允许上述有限架构工程接收，网站公开仍无豁免。无需关闭或修改Guard，不增加trusted proxies，也不设置伪造地区头。
- `APP_ENV=local`、`APP_URL=http://127.0.0.1:8406`保持真实。03当前开发快照 `a5a6b5dd0661d522fc4c1c072cf8375135ec27ae` 的SandboxApi允许local官方固定端点；testing只能使用测试transport，staging仍额外受控。此快照只是只读代码评审，不是本环境运行或最终候选。
- 当前公共精确CSRF豁免只覆盖PayPal webhook；购买/取消等浏览器操作仍保留会话、CSRF和对象权限。后续联合候选必须实际回归这一边界，不能以源码检查代替。
- 首笔本机Sandbox不证明真实邮件/地区、手机访问或外部网站部署。若本地用合成地区/邮件预览，报告必须注明本地范围，不能宣传这些条件已通过。

## 3. 配置闭环（由00在固定候选上安排，未实际执行本人步骤）

所有命令均在**新06根目录**运行。`CANDIDATE_SHA`由00填写实际当前完整40位checkout提交；工具同时拒绝业务源码脏树。不要把下面占位符当作真实SHA。

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
```

### 步骤A：录入一次，不重复抄秘密

07指导本人在已打开的8407页面填写Client ID、Client Secret、scope，其他可暂空。保存完成后关闭录入服务；不要再用首页完整保存表单补字段，因为它明确是全量替换。后续用下面的“只补ID”命令，保留已保存的Client ID/Secret/scope，无须重新手填它们。关闭服务不删除文件。

### 步骤B：候选安装/迁移完成后，先离线应用且保持off

00固定完整候选，06完成依赖和新库迁移/合成组合验证后，再由00确认可以使用本人材料。先停止本工作区应用进程（不会操作8407及其他工作区），清自己的配置缓存：

```sh
python3 scripts/dev.py stop
/opt/homebrew/opt/php@8.4/bin/php h5-platform/artisan config:clear
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA --apply
```

默认无`--apply`只检查；实际应用会先保存0600的私密`env-before-*`备份，原子写回同工作区.env，并强制 `ENABLED=false` / `NEW_PURCHASE_PROVIDER=unconfigured`。拒工作区/URL/DB/会话不匹配、配置缓存、web/queue/scheduler PID标记、商户scope/clientID/merchantID改变、活跃Stripe或新购渠道；保留其他配置。字段全部经真实dotenv解析回读，美元符号/引号/反斜杠不会变成变量引用。出错仅固定提示，不打印源文本。

### 步骤C：显式开启API准备，仍不开放新购

```sh
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA --api-only
```

这个开关工具本身不联网，只置 `ENABLED=true`，新购始终unconfigured。此时可以执行已接收03的准备命令；以下名称来源于03当前代码，最终候选上还需`artisan help`核对。产品准备需要本次PG已启动，因为03会持久化幂等操作。为避免开整个网站，可用根开发工具已有函数只恢复**本次**PG：

```sh
python3 -c 'import runpy; runpy.run_path("scripts/dev.py")["database_start"]()'
```

该函数只创建/启动此工作区PG及独立普通/测试库，不运行迁移；只能在此前已完成候选迁移的前提使用。随后由00在明确官方准备窗口执行：

```sh
umask 077
/opt/homebrew/opt/php@8.4/bin/php h5-platform/artisan billing:paypal-prepare --check-merchant > .runtime/paypal-sandbox/merchant-check.json
/opt/homebrew/opt/php@8.4/bin/php h5-platform/artisan billing:paypal-prepare --create-product > .runtime/paypal-sandbox/product-prepare.json
```

这些**会联系官方Sandbox**，本轮未执行。记录返回码，失败不继续、不擅自请求额外权限。回执文件是私密材料，不进Git或聊天。第一次merchant_id为空时得到候选身份也不等于已匹配；填写后必须再次check-merchant得到实际匹配结果。create-product依赖03幂等台账，不能每次故障换新产品绕过未知状态。

### 步骤D：只补实际ID → 应用仍off → 再按需开API

先结束上一步命令，再只关闭当前API（保留值）；没有待录入文件时off仍可执行：

```sh
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA --off
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA --complete-ids
python3 scripts/ops/paypal_apply_credentials.py --candidate CANDIDATE_SHA --apply
```

`--complete-ids`必须在本人终端，逐项不回显询问Merchant ID / Product ID / Webhook ID；留空保留，不询问Client ID/Secret/scope。只填已经取得的真实ID；已有非空ID变化会拒绝，需00核对重绑，不静默换商户/产品/回调。补录只改私密待应用文件，下一条apply才改.env。它不会调用官方，也不会启用新购。

取得回调URL和实际注册Webhook ID后同样重复**off→只补ID→apply→api-only**；无需重填秘密。若曾启动本次web/queue/scheduler，先`dev.py stop`和`config:clear`再操作；PG-only没有web PID标记，不形成循环。本人在8407仍填写时，不同时执行这些操作。

### 步骤E：正式回调验证后才能选择新购

完成字段齐备、实际商户匹配、回调验签/可靠入箱、联合回归后，由00在唯一窗口将 `H5_BILLING_NEW_PURCHASE_PROVIDER` 设为paypal，并启动8406应用；此工具故意不提供“完成录入自动开购”。00需给出最终配置操作回执，不把api-only当新购开关。候选仍必须保留恢复续费关闭，除非官方对应证据已经齐备。若00需06维护启用新购的独立操作入口，须把前置回执和确切规则一起接收后补，不能当前猜成全部已验。

## 4. 外网批准后才允许执行的命令候选

**以下未执行。** 当前只检查已安装 `cloudflared 2025.5.0` 的version/help，确认参数存在；版本较旧，00需在批准窗口核准适用版本及公告。没有安装、升级或连接Cloudflare。`--local-review`保留为显式本机工具开关，并不表达外网授权。

先在终端一运行回调桥（仅本机审查，不自动开隧道）：

```sh
python3 scripts/ops/paypal_callback_bridge.py --port 8408 --local-review
```

确认8408实际进程来自本工作区、8406对应已固定候选、APP_URL/DB正确；完成通过桥到真实03处理器的无效签名非2xx和私有路径回归。得到本人明确外网批准后，终端二才运行：

```sh
umask 077
env -i PATH=/opt/homebrew/bin:/usr/bin:/bin /opt/homebrew/bin/cloudflared tunnel \
  --config /dev/null \
  --no-autoupdate \
  --metrics 127.0.0.1:8409 \
  --management-diagnostics=false \
  --loglevel info \
  --transport-loglevel warn \
  --url http://127.0.0.1:8408 \
  --http-host-header 127.0.0.1:8408 \
  --no-chunked-encoding \
  > .runtime/paypal-sandbox/tunnel-private.log 2>&1
```

净环境和空配置用于防止继承其他任务的TUNNEL_*、代理或旧隧道配置。不得改成8406/8407；不得开debug日志、不得启用邮件PIN挑战；不使用`--no-tls-verify`。本机8409也不能转发公网。日志只在0700目录/0600文件中保留启动与连接诊断，不复制全文到公开报告；只提取新临时URL供本人注册。当前命令仅核对help，实际Quick Tunnel启动/URL取得、空配置处理及上述选项组合尚未运行验证。

官网说明Host参数控制内部Host，禁chunked参数会改变源站请求传输方式；**这不能证明最终Content-Length和原始正文一定如预期到达**。批准后先从外部用无秘密合成正文与五个合成头做字节摘要核对，再验证真实PayPal签名；桥拒chunked/压缩/变体不能为“跑通”而放宽。若实际客户端仍不符合，停止并回00修订入口，不能称成功。[官方源站参数](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/configure-tunnels/origin-parameters/)

实际生成的地址加 `/webhooks/paypal` 才是Webhook URL。没有生成地址前不提供虚构链接。事件清单沿主报告十种Sale/Subscription事件，由最终03实现逐项核对。

## 5. 关闭与仍待验证

终端二Control+C停止本次隧道，终端一Control+C停止本次桥；只按本次进程操作，不批量pkill其他任务。停止前先暂停新购并对齐待处理事实，保留收件/对账到状态明确；临时URL撤除后外部实测不可达、原App移除本次Webhook注册。URL变化必须重新核对App/Webhook ID，不能绕过“已有ID改变”保护；00制定重绑步骤后再改。Mac离线不能证明未来续费通知一定收到，周期性验证需后续稳定窗口。

代码回退不恢复旧库覆盖新Sale/退款/权益；备份和独立恢复继续按主报告执行。此桥和工具测试没有真实订单、付款、退款、队列重启或恢复证据。保留外部HTTPS、Cloudflare实际透传、官方签名、完整周期/退款/取消、真实邮件/地区/手机及部署未通过状态。
