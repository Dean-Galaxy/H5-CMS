# 02-001 Content 接入与播放上下文

状态：提交 00 协调，尚未作为冻结约定使用。

基线：`h5-foundation-v1` = `7a7ee4b57b0edcf747ba35111c68fd08db186b20`；文档/PHP/OpenAPI 契约 `1.0.0`。
工作区：`/Users/hejunxiang/.codex/worktrees/h5-02-content/H5`；分支 `codex/02-content`。

| 差异/需要 | 现状 | 建议及消费者 | 验证 |
|---|---|---|---|
| 运行契约版本 | `h5-platform/config/h5.php` 的 contract 仍为 `1.0.0-candidate.1` | 由 00 统一为实际冻结版本，模块不得自行改 | 配置与文档/OpenAPI 版本一致 |
| Content 正式装载 | provider=null / ready=false，接入文档指定由 00 修改 | 实现类预定 `App\\Modules\\Content\\ContentServiceProvider`；02 先交实现，00 在接收时正式装载。请提供开发期可使用的公共装载方式/提交，02 不在路由私自注册服务 | 本机 HTTP、队列与定时使用真实 Content；未装载仍显式 503 |
| 可信地区来源 | PlaybackAuthorizer 要求可信 region；公共 HTTP 未定义由何处取得，CatalogReader 无 region 参数但要求授权可见过滤 | 请 00 确定统一可信请求上下文及本地限制。建议 local 仅 loopback 明示 US 测试地区；正式环境未配受信地区解析则拒绝地区受限播放，不接受 query/header 自报地区。04 与 02 不分别猜地区 | 伪造 region/query/header 不能放行；授权失效同时影响目录和播放 |
| 播放会话续签消费 | nullable play_session_id 已存在，但刷新与续签的含义未文档化 | 建议新打开/刷新/切集传 null；仅当前同集连续播放续签传服务端发放的 ID，02 绑定观众或匿名会话及分集。当前免费会话只在有限连续窗口内保留原免费判断；付费始终重读 MembershipReader，下架和授权到期重新检查。请 00 发布确认语义再供 04 使用 | 改 N 的同集续签、刷新重判、切集拒用、他人会话、到期/退款/下架拒绝 |
| D03 故障漏排期 | 总台账未定；不可默认 10 分钟 | 请 00 收敛最终业务规则。正常当前时刻执行、已入队同次防重、缺素材阻断及下次正常批次可独立开发；无已确认故障补发政策的过期 occurrence 不擅自补发 | 异常门槛在 D03 定稿后补验，不能冒充全部异常已通过 |

兼容性：前两项不改 DTO/HTTP 字段；地区上下文与播放消费语义需要 00 冻结为共同技术约定，若变更字段则另发契约版本。02 不改公共定义、权限、锁文件或其他模块。
