# PayPal Live 最小接入约定

2026-11-03，00依据本人明确要求冻结：本周上线正式网站，本人使用自己的买家账号测试真钱订阅→官方付款核实→会员解锁→取消未来续费；本周不投流。自制测试剧权利由本人确认，工程不冒填授权地域/有效期。

统一源码起点9d054cd604979532e041997adb1c83c6fc45b34a；03在独立worktree实现模块和本文件所列公共增量，00接收并独占实际环境部署/配置及账户核对。已有Sandbox事实和对象保留，不转成Live，未获准项默认关闭。此约定是开发授权，不是已部署、已收费或已获商户核准。

| 范围 | 约定 |
| --- | --- |
| 配置 | 旧billing.paypal完整保留为Sandbox；新增billing.paypal_live，H5_BILLING_PAYPAL_LIVE_ENABLED默认false及具名LIVE_CLIENT_ID/CLIENT_SECRET/WEBHOOK_ID/WEBHOOK_URL/MERCHANT_ID/MERCHANT_SCOPE/PRODUCT_ID。凭据通过私密录入，不进聊天或Git |
| 新购选路 | billing.new_purchase_provider新增paypal_live，旧paypal仍Sandbox。持久provider=paypal、provider_environment=live，来自服务器已核定scope，不采信浏览器选择 |
| 环境保护 | Live真实IO仅production，HTTPS、debug=false、controlled_staging/simulation/local overrides及广告测试关闭；local/testing专项只用HTTP Fake。默认未配置，缺App/商户/Product/Plan/Webhook逐项核定即拒绝；不以staging伪装生产 |
| 回调 | 新的精确POST /webhooks/paypal/live；该唯一路由免CSRF并按Live验签/商户App绑定核实，其他表单CSRF保留。原Sandbox回调及对象不变，Nginx尚未放行本新路由 |
| 价格与账本 | 正式报价只取明确is_test=false的价格；旧测试价格不自动转正式。同账本复用核账/幂等/权益/取消与退款事实处理，按持久环境记录is_test，PaymentSettled既有DTO字段不变。关闭Sandbox不阻断Live旧单生命周期，反之亦然 |
| 首版范围 | 一次新订阅首款核实、到账解锁、取消未来续费与必要重复通知/失败边界；保留已有退款与续费处理，不新增恢复订阅或历史实验。不中断已付款权益、不修改老付款事实 |
| 不做 | 无Stripe Live、投流/新采集、不复制旧Sandbox账务或凭据、不重做UI/支付系统、不自动发起真钱交易/退款、无外部API或云端操作（候选开发阶段） |

03可修改公共根config/billing.php、RuntimeEnvironmentGuard的上述必要增量及模块、向前迁移和专项测试；不得改其他模块配置或历史冻结文档。00独立复核后串行接收。旧Sandbox通过证据复用，仅新分支及受影响隔离/账务/生产拒绝路径专项验证；不全套重复、不过度扩项。生产前必须备份、能回退配置并保留新支付事实；正式App与账户由工程核现有配置，不让本人重复注册。本人亲自批准收银台金额和付款。
