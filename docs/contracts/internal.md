# 内部接口候选 v1

全部 PHP 接口位于 `h5-platform/app/Contracts`；值对象位于 `app/Data`，状态枚举位于 `app/Enums`。签名是代码约定，JSON 字段及可空规则以 openapi.yaml 为准。返回 DTO 可 `toArray()` / JSON 序列化；`fromArray()` 是类型装配入口，不替代业务输入校验和 JSON Schema 校验。

| 接口 / 方法 | 参数与返回 | 失败与约束 |
|---|---|---|
| IdentityContext.viewer / staff | 无参数；返回当前 ViewerIdentity / StaffIdentity 或 null | 区分 web / staff guard；停用身份为空，不接收请求自报身份 |
| requireViewer | verified=false；返回 ViewerIdentity | 未登录 401 AUTHENTICATION_REQUIRED；verified=true 且未验证 403 EMAIL_UNVERIFIED |
| requireStaff | Permission；可空 ResourceScope；返回 StaffIdentity | 401 未登录；403 MFA_REQUIRED / NOT_AUTHORIZED；ResourceScope 只接受业务提供方从数据库建立的范围 |
| AuditRecorder.record | action、subjectType、可空 subjectId、result、changes=[]；void | 01 自动补 actor、UTC 时间和 request_id；禁止传请求体、邮箱、令牌、媒体链接；changes 白名单见实现 |
| CatalogReader.catalog | cursor=null，limit=20（1–50），locale=en（en/zh_CN）；CatalogPage | 02 未接入503；未来只返回公开目录；cursor 不透明，失效返回422 |
| CatalogReader.drama | dramaId、locale=en；Drama | 02 未接入503；不存在或不公开404，不透露隐藏媒体 |
| MembershipReader.membership | 可信 viewerId、DateTimeImmutable at；Membership | 03 未接入503；没有权益与依赖未就绪必须分开。状态未知时 payment_resolution_required=true |
| BillingService.offer | 可空 ViewerIdentity；Offer | 03 未接入503；游客优惠资格为 null；报价不等于锁定结算金额 |
| BillingService.checkout | ViewerIdentity、CheckoutInput、idempotencyKey、可空 AttributionSnapshot；Checkout | 必须核对已验证身份、报价、已有订阅/订单、幂等请求；EMAIL_UNVERIFIED/STALE_VERSION/BILLING_PENDING/SUBSCRIPTION_EXISTS 等按 OpenAPI；目前503 |
| BillingService.order | ViewerIdentity、orderId；Order | 只读本人订单；他人及不存在统一404；当前503 |
| BillingService.cancel | ViewerIdentity；Membership | 03 核对原订阅与平台结果；未知不能返回成功；当前503 |
| BillingService.resume | ViewerIdentity、ResumeInput；Membership | 主动确认原价和下一续费时刻；禁止创建第二订阅；当前503 |
| BillingService.cancelForSupport | StaffIdentity、viewerId、verifiedCaseId；Membership | 03 必须从自身已核实客服记录检查身份与意愿，并调用 requireStaff(SubscriptionCancel, 服务端范围)；客服不得恢复；当前503 |
| PlaybackAuthorizer.authorizePlayback | 可空 ViewerIdentity、episodeId、可信 region、playSessionId=null；PlaybackGrant | 02 未接入503；匿名免费许可受支持；业务拒绝用 allowed=false 与 reason，所有许可字段为 null；状态规则见 Schema |
| ProgressService.progress | ViewerIdentity、dramaId；Progress 或 null | 04 未接入503；本人尚无记录时 null，不丢弃已有记录 |
| ProgressService.saveProgress | ViewerIdentity、dramaId、ProgressInput；Progress | 04 必须校验分集归属、有效时长、会话序号/延迟写入；不能授予播放权限；当前503 |
| AttributionService.enabled / snapshot | bool；snapshot 接可空不可信来源标记，返回 AttributionSnapshot 或 null | 05 未启用时 false/null，不阻塞未来付款；没有请求时自动拼造来源的路径 |
| PaymentSettled.v1 | PaymentSettled 值对象，无本站可写公开入口 | 03 唯一生成，05 消费；event_id 去重、付款已核实、稳定来源快照、is_test；没有可执行发布器或发送成功桩 |

共同异常为 `App\Support\ApiProblem`（errorCode、status、retryable），缺提供方用 `DependencyNotReady`（503）。HTTP 映射保持统一错误结构，内部消费方不能捕获 503 然后返回无会员、已取消或已付款。业务输入校验、状态机与成功响应均由各提供方实现，01 不代写业务。

## 类型消费示例

```php
// 结构消费，仅在测试中读取文档样例；普通运行不自动加载 examples。
$sample = json_decode(file_get_contents($pathToExample), true, flags: JSON_THROW_ON_ERROR);
$membership = App\Data\Membership::fromArray($sample);
assert($membership->toArray() === $sample);

// 实际模块入口从服务端取得身份；不从 request('viewer_id') 拼装身份。
$viewer = app(App\Contracts\IdentityContext::class)->requireViewer(verified: true);
// 后续 03 的实现接收上述身份；目前其骨架明确抛出 DependencyNotReady。
```

`tests/Feature/Identity/ContractsTest.php` 执行正例 DTO 往返消费、未就绪行为、路由/枚举与 OpenAPI 对齐。`scripts/validate_contracts.py` 执行 Schema 与正反例校验；`scripts/smoke_http.py` 消费真实身份响应。未来提供方增加成功响应时，必须加入同一套 Schema 验证和自己的对象权限/状态机测试。
