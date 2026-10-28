# 03-005 PayPal商户与App初始化绑定（00已接收方向）

当前业务契约1.0.5 / paypal-sandbox-v1；2026-10-28真实联调由00确认：OAuth200，Balances403，token没有balances/read。不得把该权限作为订阅接入门槛。03未读取06密钥、环境或服务。

## 官方依据与可证明的边界

- https://www.paypal.com/us/cshelp/article/how-do-i-create-paypal-rest-api-credentials-ts1949 ：Merchant App创建时选择关联Sandbox Business账号。
- https://developer.paypal.com/api/rest/ ：Client ID识别App，Secret认证App，Client Credentials token认证后续API调用；这不是单独的Merchant ID查询接口。
- https://developer.paypal.com/api/subscriptions/v1/schema.json ：订阅对象/交易需独立核对；没有把OAuth app_id或订阅ACTIVE解释为商户ID证明的依据。

## 提议

1. 删除运行前置Balances调用，不替换为另一项报表权限。保持显式未绑定即拒绝订阅操作。
2. 初始化时本人/07在官方Dashboard同一个Sandbox Merchant App核对关联Business账号，并在该账号详情核对Merchant/Account ID；核对的是已经私密保存的同一Client ID，Secret无需展示或发送。00/07保留私密核对依据，给出不含个人资料的回执编号。
3. 模块专用CLI只在显式执行时封存绑定：provider/environment/merchant_scope、Client ID SHA256、merchant_id、来源operator_dashboard、核对回执编号、时间。再做官方OAuth和该App可访问产品的读取。不得由仅手填ID、默认环境布尔值或未验证OAuth app_id自动建立绑定。
4. 同scope绑定不可替换；同Client ID不能静默重绑另一个商户/scope。换App/商户必须独立复核，原订单/订阅仍按旧scope。Secret轮换只要Client ID不变且官方认证成功可继续。
5. 后续运行必须匹配封存指纹/商户/scope，并通过官方认证及产品/计划/订阅/通知签名的原有对象链核对。Webhook ID应读官方Webhook对象验证属于当前App；失败不接收通知。只保留最小字段，不保存API响应、secret、token、余额或账号邮箱。
6. 准备命令输出分开标识app_authenticated、merchant_binding_status/source与official_payment_verification，不将人工Dashboard核对报告成API自动核验merchant_verified=true；API-only预检不会建单或开启新购。

## 消费者与兼容

影响03模块准备命令、06私密配置/诊断工具、07绑定指导；不改业务DTO/OpenAPI，不新增根配置字段。新绑定表及命令归03。00更新公共附录中的核对来源说明并评审06输出兼容。已有无绑定的PayPal配置安全拒绝，不能自动迁移为已验证。

## 验证要求

无绑定/空回执/未确认不通过；OAuth成功也不能自动绑定；更换client/merchant/scope拒绝；并发初始化不可覆盖；API失败不落有效绑定；Secret轮换后需重新认证；绑定不会创建订单/付款/权益；默认离线命令不联网；所有流程无Balances调用；Webhook错App拒绝。保留原Sale/周期/退款/取消测试。

00已以36b0832接收来源语义，并以ef321908加入Webhook URL配置；实际模块交付与精确CLI见docs/handoffs/03-PayPal商户绑定修复-2026-10-28.md。真实绑定仍须本人/07实际核对回报，不得默默回退为信任手填ID或提前生成确认。
