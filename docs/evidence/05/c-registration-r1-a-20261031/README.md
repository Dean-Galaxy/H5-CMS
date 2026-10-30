# 05 注册R1-A隔离证据

2026-10-31。基线`6d22515a47fe315bd3aba1279c8136c0f409c3a4`，主源码`e94a23472ec8e9fa38f39736e0aaac1d3566ff86`及期限补丁`7ff8c47dcb364c2c4ada6ae351982adec5bf4536`。仅合成/去敏摘要，不含.env、实际身份/账务、凭据、Cookie/CSRF或原始运行材料。

| 文件 | 内容 |
|---|---|
| manifest.json | 精确源码/合并起点、22文件摘要、计数与未验边界 |
| acquisition-regression.txt | 最终组合151项/2862断言，只去ANSI及行尾空白 |
| http.json | 新真实HTTP/服务进程26项，含必要账号、4进程CAS/重放；2次自建HTTP均停；不是01-A条款/01-B共享接线或浏览器验收 |
| migration-upgrade.json | 新标记合成库25→26、67旧表旧列摘要、回滚重施通过；新增3表及2可空字段 |
| contracts.txt | 本批OpenAPI1.0.5/59旧公共样例；新R1公共机器契约由00后续发布 |
| composer-audit.json | 本批locked audit公告0/废弃包0 |
| verification.json | 依赖/25旧迁移不变，新迁移/收尾/原服务及默认关闭边界 |

测试库由自己的dev.py root校验与归属标记创建，三个`_r1a_*_test`库与原05库隔离。升级库有合成privacy及C偏好/主体/会话样本；所有旧业务表原列逐一比对。HTTP清洁库实际应用26迁移；并发worker只在testing且根/库标记吻合才可执行，无公开入口。HTTP只停止自身进程组，不管理其他站。

选择proof绝对24月，回执最多24h；过期不用、bounded prune验证在冻结时钟，实际清理调度及备份恢复另验。普通运行R1/新结果默认关；外发disabled。本批不实现持久C账户边、付款/渠道映射、Owner完整漏斗、真实资格或生产。API身份测试用原01注册，不冒称01-A新条款或01-B消费者已通。Meta/TikTok测试为明确模拟/替身，未做平台HTTP或真实确认。

接口、配置、模块内capture/record职责与后续公共端口接收见[交接](../../../handoffs/05-C注册R1-A交付.md)。
