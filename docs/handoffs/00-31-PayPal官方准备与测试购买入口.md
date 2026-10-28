# 00 PayPal官方准备与测试购买入口

2026-10-28，接续00-30。用户已在07完成同一Sandbox App、关联Business测试账号及Merchant ID的三项本人确认，私密回执已由00核对并使用。本文不包含真实ID、凭据、私人URL或个人验收资料。

## 官方准备已完成

00在独立8406/5906环境依次实际执行持久商户绑定、重新认证、创建官方测试DIGITAL产品并读回。绑定来源是operator_dashboard；OAuth只证明App认证，api_merchant_identity_verified仍为false，不能声称PayPal API自动验证商户身份。

06恢复本次已授权的callback-only窗口，正常TLS下15项范围及5项传输检查通过，00接管关闭责任。00自动登记同App Webhook（官方创建201、按ID读回200），精确URL及十项事件匹配，再通过03的--check-webhook正式读回。用户无需再次手填Webhook ID。此时API-only=true、新购unconfigured；没有创建订阅、付款或权益。

窗口只转发/webhooks/paypal；后台、媒体、密钥页不公开。真实URL、官方资源和请求回执仅保存在06工作区.runtime/paypal-sandbox的0600私密文件。窗口需要重启时必须核对新URL和官方登记，不能沿用昨日地址或凭进程存活认定官方支付成功。

## 工程增量接收

- 03只读准入源码e50d7f2、交付c876627：--check-readiness现场重新OAuth并核对持久绑定、官方DIGITAL产品及精确Webhook/事件，不创建对象或付款。
- 06安全开关源码942e6a4、交付3f96760：固定候选、隔离停机、共享锁、当前活动窗口、官方ready、配置前后状态共同约束；开启仅切换Sandbox新购，关闭无需网络/PG/源凭据，保留已有身份和数据。恢复步骤见docs/06-PayPal购买安全开关.md。
- 00独立支付绑定/运行/公共配置38项771断言通过，72项工具回归通过，无skip。初跑37通过1失败为公共CSRF测试未建立audit表，00仅增加测试DatabaseMigrations隔离（cfd90e5），复跑通过。原失败与最终日志均保留；不是生产逻辑修补。
- 组合工程候选83ecaca包含以上提交；实际接入、官方readonly ready和开启结果将在本文追加，以实测为准。

## 范围与后续

本轮只允许官方Sandbox；Live、正式收费、真实邮件和完整外网网站仍未放行。新购开关成功、PayPal跳转或浏览器成功页都不能代替官方签名、实际付款记录、账本和权益核验。07收到00明确可用入口后，才指导本人用Sandbox Personal买家完成第一笔测试付款；随后00核对服务器事实，再安排取消未来续费测试。

周四8377/5877及不可移动标签h5-local-accepted-2026-10-22不动。工程每版按既有公开GitHub授权扫描并上传，私密配置/数据库/素材不在代码备份内；上传状态以远程回读为准。

## 实际接入及测试入口放行

00已将真实06工作区从64e8273261dd0c2f0f751a383d548edc9233ccb4停机快进到**ec3f6124f4e2e0c575c08ac144b2768de4a55b3f**。代码接入时原env/私密凭据字节保持；19迁移无新增，九张账务表计数保持。该候选已在GitHub应用分支及不可移动h5-paypal-readiness-tools-2026-10-28标签推送并回读一致，周四标签仍78cb664。

随后实际--check-readiness退出0，configuration、network意图、binding、app认证、Webhook、product及purchase readiness均true，诊断null且missing为空；人工来源/operator与API身份未自动验证语义保持。安全开关现场再次执行官方readiness，通过后仅将H5_BILLING_NEW_PURCHASE_PROVIDER从unconfigured切换paypal；API仍为之前已批准的Sandbox true，其余env字段与暂存凭据保持。没有创建付款或订阅。

实际dev.py start五项健康通过。Chrome 156实际浏览游客和合成观众购买页，9项检查通过：页面200、测试价、登录、确认框及支付按钮；没有提交checkout，没有外部浏览器请求。官方callback窗口用正常TLS重新验证GET首页/后台/回调404、缺签名POST400四项通过。这不代表官方签名或支付成功。内嵌浏览器工具因旧授权根路径含符号链接无法启动，本轮使用独立真实Chrome检查，不修改全局Codex权限。

当前入口为 **http://127.0.0.1:8406/membership?purchase=1**，只供本Mac；测试价首周USD3、随后每周USD12，非正式定价确认。已有订单/订阅/付款/退款/权益/收件/出件及观察为0，既有provider intent为1；不将产品准备意图记为付款。07已收到完整人工买家指引要求，使用普通H5观众（需验证邮箱）和独立PayPal Sandbox Personal买家，不能使用后台员工或Business卖家代替。

当前公网回调窗口为**活动状态**，只用于本次仍在进行的测试；本人需保持Mac联网。00负责收到暂停或测试结束后停止网站、用下列专用工具关闭新购/API，再关闭匹配本窗口的桥/隧道，保留数据；不以已公开入口误称整站外网或Live放行：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
python3 scripts/dev.py stop
python3 scripts/ops/paypal_purchase_switch.py --candidate ec3f6124f4e2e0c575c08ac144b2768de4a55b3f --disable
python3 scripts/ops/paypal_stop_callback_window.py --stop
```

此完整SHA是当前真实运行候选，后续报告提交不擅自快进活动测试环境。切换前核对本地HEAD；停止失败时不得声称窗口已关。重开窗口若URL改变，需要官方登记读回；本次私密一次性Webhook创建脚本不能盲目重跑或替代更新已配置Webhook的评审。

当前已放行的是**本人第一笔Sandbox测试购买操作**。官方签名、真实Sandbox付款、订单金额币种/商户匹配、账本与权益、取消未来续费均待本人操作后由00/03逐项核实；网页回跳不构成验收。证据见同日00目录official-readiness-sanitized、enabled-runtime-preservation、enabled-entry-browser及enabled-callback-boundary文件。最终报告按新标签留存，远程结果由00回读确认。
