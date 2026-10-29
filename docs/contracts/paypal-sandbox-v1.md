# PayPal Sandbox 公共运行约定 v1

2026-10-27由00冻结本配置附录；业务DTO/OpenAPI仍1.0.5，旧标签不移动。此附录是模块接线约定，不是官方支付通过或公网部署放行。03接收记录必须说明真实处理器是否已实现。

## 配置与兼容

根`h5-platform/config/billing.php`提供公共配置，03模块config.php保留原Stripe配置。07指导本人写入该独立测试工作区未跟踪`.env`，不在聊天/仓库/日志提交凭据。

| 配置 | 环境变量 | 规则 |
|---|---|---|
| billing.new_purchase_provider | H5_BILLING_NEW_PURCHASE_PROVIDER | unconfigured / stripe / paypal；未设时，旧H5_BILLING_DRIVER=stripe_test映射stripe，其他映射unconfigured |
| billing.paypal.enabled | H5_BILLING_PAYPAL_SANDBOX_ENABLED | bool，默认false；有凭据不自动启用 |
| billing.paypal.client_id | H5_BILLING_PAYPAL_SANDBOX_CLIENT_ID | 服务器专用，默认空 |
| billing.paypal.client_secret | H5_BILLING_PAYPAL_SANDBOX_CLIENT_SECRET | 秘密，默认空，不输出 |
| billing.paypal.webhook_id | H5_BILLING_PAYPAL_SANDBOX_WEBHOOK_ID | 本App实际注册ID；拒绝模拟器字面量WEBHOOK_ID |
| billing.paypal.webhook_url | H5_BILLING_PAYPAL_SANDBOX_WEBHOOK_URL | 可空以完成准备；运行需实际注册HTTPS地址，03官方读回后精确比较。只允许域名、可选443端口及/webhooks/paypal，不含用户信息、查询或片段；不作为出站请求目标 |
| billing.paypal.merchant_id | H5_BILLING_PAYPAL_SANDBOX_MERCHANT_ID | Sandbox卖家ID，由03核对实际收款方 |
| billing.paypal.merchant_scope | H5_BILLING_PAYPAL_SANDBOX_MERCHANT_SCOPE | 固定商户别名，首位小写字母/数字，其余小写字母/数字/下划线/连字符，最长64；换商户另scope |
| billing.paypal.product_id | H5_BILLING_PAYPAL_SANDBOX_PRODUCT_ID | 本App Sandbox产品ID，由03核验；无虚构默认产品 |

不提供API主机覆盖或“官方已通过”环境开关。03固定访问api-m.sandbox.paypal.com，不读取浏览器金额/商户/产品。新购渠道选择不修改已存订单和订阅的provider/environment/merchant归属；旧Stripe订阅仍按原渠道处理。缺凭据允许安全开机，但任何相应操作必须明确未就绪，不能返回假成功。

local/testing可显式使用Sandbox；production拒绝启用；staging须controlled_staging=true并满足既有HTTPS、地区、邮件、会话和直连TLS检查。整站公网仍无豁免；本机Sandbox回调专用入口仅按下述专项约定评审，不能据此开放网站或绕过staging检查。每次运行检查重读配置，缓存不能绕过。

## 回调与网页

仅`POST /webhooks/paypal`精确CSRF例外，由03注册真实处理器，服务器验证官方签名后可靠收件才确认。无通配豁免；浏览器购买/取消/恢复仍检查会话、CSRF、身份和对象权限。支付回跳不记付款；ACTIVE不能代替收款事实。只有03唯一账本的已核验周期权益可授予观看。

D02已接专项：Offer有效期30分钟；PayPal Checkout.expires_at=null明确平台期限未知。31分钟是本站停止提供旧审批链接并核对的期限，不能承诺平台强制过期；未确认终态不释放唯一订阅占位，真实迟到款按原订单核实。

F01恢复续费未完成官方原价、原时间、不立即扣款验证前不开放。取消续费按真实suspend并重读状态确认，保留已付本期、不自动退款。退款按真实结果同步，部分退款保留本期、整笔全额只撤销对应付款权益，同时停止未来续费，不碰其他有效周期。

## 00本段验证

共享配置/环境Guard/精确CSRF测试与既有RuntimeEnvironmentGuard合计14项、365断言通过。包括生产及未受控staging拒绝、坏类型/控制字符/模拟器Webhook/自定义主机拒绝，精确回调可达而相邻路径/账务浏览器POST缺CSRF返回419。未联系PayPal、没有使用真实凭据，没有运行新业务迁移、没有开放公网或改变周四手测站。


## 2026-10-27 回调专用入口专项（工程接收，不是外网启动批准）

00复核06候选c4b1ff0caf24204856270e485aca906fd466b336并独立运行16项本机测试通过，接收以下有限架构。联合03真实处理器及公网实际透传仍待验证；用户批准前不得启动隧道。

- 使用独立06工作区8406/5906；APP_ENV=local、APP_URL=http://127.0.0.1:8406，不伪称staging、真实地区、直连TLS或外部网站验收。保留周四8377/5877。
- 外部HTTPS只能指向本机8408回调桥；桥只允许原始目标精确POST /webhooks/paypal，无查询参数或其他路径。固定转发到本工作区127.0.0.1端口及同一路径，不使用请求提供的主机/URL，拒绝重定向。
- 最多256KiB原始正文、4个并发、20秒总期限。只透传五个PayPal验签头和Content-Type/Length；拒Cookie、Authorization、重复关键头、压缩、chunked。所有代理IP/地区/Proto头丢弃，不添加可信代理配置。
- 隧道必须固定内部Host到桥的127.0.0.1:8408；桥固定上游Host到127.0.0.1:8406。此Host仅用于内部路由，不是外部客户身份或HTTPS事实。
- 返回上游状态及空正文，不转发Cookie、Location、错误细节；3xx转换为502，上游断开为非2xx。03仍须验证官方签名并可靠收件后确认，验签未配置/失败不能2xx或授予权益。
- 8407密钥表单、员工后台、观众网站、媒体、数据库均不接入隧道；真实密钥只留本机未跟踪文件。临时网址与停止方式由06交具体操作记录，不持久化正文或密钥日志。
- 开启前完成固定代码接收、本机攻击回归和03处理器组合验证，再向本人说明公开的唯一入口及停止方式并取得明确批准。批准后仍需验证实际HTTPS可达、私有路径拒绝、官方事件验签与账务闭环；测试工具的伪造事件和返回2xx不算官方付款。

官方Quick Tunnel用于临时开发测试，网址每次重启会变且无可用性保证；停进程后不可达。网址变化须在原Sandbox App更新Webhook并核对ID，不能直接沿用旧URL。
来源：https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/


## 2026-10-28 商户初始化绑定补充（00接收方向，运行实现待验）

本轮官方OAuth200、余额接口403且无余额读取scope，确认不能将余额接口作为普通订阅应用的必需前置。新增可选webhook_url配置以核对专用桥的真实外部地址（不能用本机APP_URL代替）；公共业务DTO/OpenAPI1.0.5不变；下述仅明确商户初始化的证明来源，不是付款或商户资格通过。

- 07指导本人核对已私密保存凭据对应的同一Sandbox Merchant App，以及该App关联的Business账号；再核对该账号详情中的Merchant/Account ID。记录实际核对来源、时间和本机私密回执引用。单独填入ID、另一账号的截图、OAuth app_id或OAuth200都不能替代此对应关系核对。
- 03以显式管理CLI封存带来源的人工绑定，持久绑定scope、Client ID SHA256、Merchant ID和核对回执。运行缺失/不匹配时拒绝相应付款操作，不能自动创建绑定或静默换账号。Secret轮换不等同改App，仍需官方认证通过；更换Client ID、Merchant ID或scope必须独立审查及迁移，不能重用原账务归属。
- 诊断必须将人工绑定有效与API自动识别商户分开：例如merchant_binding_valid、binding_source=operator_dashboard、api_merchant_identity_verified=false。不得把这种初始化写成官方自动返回Merchant ID，更不得冒充订阅资格、签名或已付款。
- OAuth只证明当前App凭据有效。官方资源读权限只证明该凭据有权访问相应资源，不推断Catalog/计划为该App独占。产品、计划、订阅、本地订单关联、scope、金额/币种/周期和实际付款仍按原链路逐项核对。Webhook ID需由当前凭据官方读回，核对该App注册入口；不能采用模拟器ID或用其他App的事件。
- 不新增非订阅Orders测试对象来探测商户，不放宽正式环境检查、回调签名或唯一账本规则。CLI输入/回执和官方资源验收由03固定交付后经00复核；部署和本人操作入口由06/07衔接。

依据：[PayPal REST凭据创建说明](https://www.paypal.com/us/cshelp/article/how-do-i-create-paypal-rest-api-credentials-ts1949)说明Merchant App选择关联Sandbox Business账号；[REST入门](https://developer.paypal.com/api/rest/)区分Client ID标识App与Secret认证App。人工核对方案为本项目在这些官方边界下的工程设计，不是PayPal新增的身份API。


## 2026-10-28 官方响应兼容与周期证据（00接收方向）

首次官方Sandbox交易暴露本地样例对分页元数据和首周期边界的额外假设。以下兼容方向已由00接收，03实现和实际部署须另经测试及现场复核；业务DTO仍1.0.5，不代表正式收费通过。

- 交易列表total_items/total_pages为可选；有值必须严格与结果一致。任意大小写next分页线索拒绝，不追随未受控链接；缺少计数不能单独视为完整。必须由原订阅官方执行次数、去重后的全部交易、逐笔Sale、last_payment及两次稳定读共同证明完整性。
- 保留已有USD整数金额转换；官方0/1/2位十进制字符串可接受，不使用浮点数，不放宽币种、金额、商户scope、原订阅/订单/计划关系或未知付款状态。
- 仅首次唯一已付款周期，在原计划为固定WEEK/1、无覆盖/未来调价、无欠款和失败、交易与Sale完成且金额/归属/时间一致、执行次数为1并稳定读回时，可持久化官方[start_time,next_billing_time)。该区间须为正且不超过一个WEEK，付款时刻须落入区间；不以当前时间加七天，也不添加任意误差容忍。
- 已保存的官方周期边界不能因后续读取重算。后续只有从已确认首个边界按冻结WEEK/1连续推进，并与最新官方边界和完整付款历史逐项一致才补记。没有既有可信锚点的非整周多笔历史必须待核，不能反推或批量猜测。
- Sandbox首期可能与固定168小时假设不符，本项目如实记录经过证据链确认的平台边界；不得据此宣称正式产品已承诺或验收不足一周的服务，也不得把测试平台行为当作已批准的新定价/时长规则。正式收费前仍需验证实际扣费计划和用户展示一致。
- 原付款的显式重新核对仅查询并处理原订单；不能顺带创建第二订阅或执行过期取消。返回审批链接必须来自当前官方原订阅读回且确为APPROVAL_PENDING；ACTIVE或状态未知不再使用数据库缓存的旧审批地址。
- 通知验签和查账是两条独立证据；查账恢复权益不能代替官方通知验签验收。诊断只能记固定枚举阶段、状态码及错误类别，不记录原请求/响应、凭据、原始ID、审批链接或个人数据。


### 已验签通知的秒级收件时间

ProviderInboxStore沿用既有UTC整秒调度语义，在SQL写入前先按BillingStore::iso截断小数，插入和冲突比较使用同一canonical值；避免PostgreSQL timestamp(0)对.5以上小数上舍下一秒。原Webhook正文和五头仍原样交官方验签，只有验签通过后的收件元数据规范化。金额、Sale付款时刻与权益周期的证据读取不因此降精度。

同一scope/event ID且type/object/related/UTC秒均一致时，同一秒内的小数格式差异按重复通知处理；跨秒或其他身份字段冲突仍拒绝。没有新迁移、不改已采用迁移、不批改旧收件记录，不能用规范化绕过官方付款核对。
