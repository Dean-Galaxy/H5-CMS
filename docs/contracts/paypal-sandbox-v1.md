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
| billing.paypal.merchant_id | H5_BILLING_PAYPAL_SANDBOX_MERCHANT_ID | Sandbox卖家ID，由03核对实际收款方 |
| billing.paypal.merchant_scope | H5_BILLING_PAYPAL_SANDBOX_MERCHANT_SCOPE | 固定商户别名，首位小写字母/数字，其余小写字母/数字/下划线/连字符，最长64；换商户另scope |
| billing.paypal.product_id | H5_BILLING_PAYPAL_SANDBOX_PRODUCT_ID | 本App Sandbox产品ID，由03核验；无虚构默认产品 |

不提供API主机覆盖或“官方已通过”环境开关。03固定访问api-m.sandbox.paypal.com，不读取浏览器金额/商户/产品。新购渠道选择不修改已存订单和订阅的provider/environment/merchant归属；旧Stripe订阅仍按原渠道处理。缺凭据允许安全开机，但任何相应操作必须明确未就绪，不能返回假成功。

local/testing可显式使用Sandbox；production拒绝启用；staging须controlled_staging=true并满足既有HTTPS、地区、邮件、会话和直连TLS检查。当前没有额外公网豁免，隧道/部署方案需06交具体证据后评审。每次运行检查重读配置，缓存不能绕过。

## 回调与网页

仅`POST /webhooks/paypal`精确CSRF例外，由03注册真实处理器，服务器验证官方签名后可靠收件才确认。无通配豁免；浏览器购买/取消/恢复仍检查会话、CSRF、身份和对象权限。支付回跳不记付款；ACTIVE不能代替收款事实。只有03唯一账本的已核验周期权益可授予观看。

D02已接专项：Offer有效期30分钟；PayPal Checkout.expires_at=null明确平台期限未知。31分钟是本站停止提供旧审批链接并核对的期限，不能承诺平台强制过期；未确认终态不释放唯一订阅占位，真实迟到款按原订单核实。

F01恢复续费未完成官方原价、原时间、不立即扣款验证前不开放。取消续费按真实suspend并重读状态确认，保留已付本期、不自动退款。退款按真实结果同步，部分退款保留本期、整笔全额只撤销对应付款权益，同时停止未来续费，不碰其他有效周期。

## 00本段验证

共享配置/环境Guard/精确CSRF测试与既有RuntimeEnvironmentGuard合计14项、365断言通过。包括生产及未受控staging拒绝、坏类型/控制字符/模拟器Webhook/自定义主机拒绝，精确回调可达而相邻路径/账务浏览器POST缺CSRF返回419。未联系PayPal、没有使用真实凭据，没有运行新业务迁移、没有开放公网或改变周四手测站。
