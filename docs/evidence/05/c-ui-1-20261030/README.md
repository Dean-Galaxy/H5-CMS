# 05 c-ui-1 兼容证据

2026-10-30。00固定基线`841bed779a8a7157c92f0e8fc6940de4ff83e142`，05源码`5c19f807d9bb0c28d6da5bfa5d19b31c5e0452b3`。仅公开合成、脱敏验证摘要，不含.env、凭据、Cookie/CSRF值、真实身份/账务/媒体或原始运行资料。

| 文件 | 内容与实际边界 |
|---|---|
| manifest.json | 源码/基线、11文件摘要、实际计数及未验项 |
| acquisition-regression.txt | 最终模块123项/1643断言；只去ANSI及行尾空白 |
| http.json | 25项实际本机HTTP，四次自建服务均停；不等同浏览器或本人验收 |
| migration-upgrade.json | 24→25；67旧业务表原列数据摘要不变，新增两可空字段，回滚再应用通过 |
| contracts.txt | OpenAPI1.0.5、59样例通过 |
| dependency-files.json | composer.json/lock与841bed7逐字相同及SHA256 |
| composer-audit.json | 本批实际locked audit公告0、废弃包0 |
| shutdown.json | 自建HTTP零残留；05既有服务/PG保留，其他环境未操作 |

复验工具仅操作本工作区有归属标记的三份`_cui1_*_test`合成库，拒绝配置缓存/复制的工作区配置/无标记旧库；不复用真实数据。干净安装25项在HTTP合成库完成，升级库保留四张有合成样本的表，其余表也逐一核对原列摘要。迁移回滚不等于可以删除实际拒绝证据。

Meta/TikTok独立授权的路径只做明确本地模拟；回归中的HTTP发送由测试替身控制，未发平台请求，不是HTTP平台接受或真实事件确认。新C页面/08复看/06新环境/本人0/3另验；真实资格、账号/付款增强、UTM/广告及公网未放行。完整边界与复跑步骤见[兼容交接](../../../handoffs/05-C-ui-1兼容交付.md)。
