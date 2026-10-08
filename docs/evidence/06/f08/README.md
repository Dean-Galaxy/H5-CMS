# 06 F08及Billing环境适配证据

最终实测源码 **7c24392154bb5738c91fe6e561ba52e4bd30a2fd**，00指定应用候选 **f273e2710c9965dd6f8c565bd5dd1055d13a67cf**，实际契约1.0.4。之后仅更新06文档/证据。

| 文件 | 证明内容 |
|---|---|
| reproduction.json | **旧7fcf16b**在06自己新副本复现F08：3个未付账户在先，第一账户无权益而本次付款观众有权益 |
| pre-adaptation-probe.json / .txt | **合并1e2d5ef**上旧探针的精确失败：期待旧英文，实际BILLING_STAGING_NOT_ENABLED |
| affected.txt | 最终固定7c24392，92项/2568断言，25.71秒；含4项新恢复回归及相关Billing用例，不是全套312项 |
| runtime.json | 最终7c24392，44个离线场景；安全开关正反例、空凭据未就绪、配置缓存/进程及独立工作区身份 |
| backup-restore.json | 最终7c24392真实恢复：47表、42媒体摘要相等，独立PG恢复并实际停启；3个旧账户不变、同组11个对象ID |
| cleanup.json | 目标清单0600、独立恢复实例已停止；副本和证据保留 |
| contracts.txt / format.txt | 实际契约1.0.4/57样例、4个PHP文件格式通过；已有LibreSSL提醒保留 |
| validation-results.json | 固定版本的串行命令、退出码和墙钟时间；测试框架/恢复内部耗时见各自输出 |
| manifest.json | 版本、范围、应用/源码摘要、证据SHA-256；历史两批证据保持原样 |

原始失败库、成功源测试副本、恢复集群、备份、媒体和targets.json只保存在06私有.runtime。这里没有凭据、数据库内容、邮箱/密码或MFA秘密。对象ID仅为本次合成测试对象；恢复报告保存的是数据行摘要。归档命令输出仅去掉ANSI颜色、行尾空格和多余结尾空行；私有原始输出保留。

恢复中付款是testing FakeGateway；探针Stripe凭据为空、SMTP为合成值，禁止外部网络函数。无官网付款、真实邮件、对外HTTPS、真机、云主机重启或异地取回证据。旧MSE/HTTP结果仍保持旧SHA。详细复现、修复、操作步骤见 [报告](../../../06-F08恢复对象绑定与Billing适配.md)。
