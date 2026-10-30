# 05 c-core-1 隔离核心证据

2026-10-30。核心提交`cd0f3a4fb4c4e15969d21a5fbdaebb7b433b492f`，最终锁键收敛源码`78bb9ade15804c78d7e151f0c436f6c391c8dce5`，00基线`09273e671da98422fd93d9e18b92b58a72bd52da`；工程约定`first-party-measurement-c-v1 / c-core-1`。本目录仅公开无用户信息的合成验收结果，不包含.env、Cookie/CSRF值、数据库、原始请求、真实账户/交易或素材。

| 文件 | 实际内容与边界 |
|---|---|
| manifest.json | 固定基线/源码及14文件SHA256、验证计数、未宣称项 |
| core-tests.txt | 核心提交的新C专项24项/300断言；最终补丁模块回归包含同组24项；后续冻结时钟/模拟目录，不是新播放器或真实资格验证 |
| acquisition-regression.txt | 固定源码Acquisition组合107项/1437断言；包括原本地资产/权限/付款/适配测试，外部调用由测试替身控制 |
| http.json | 最终默认关/显式合成开启两轮实际回环HTTP共20项，真实CSRF419、同源403、409及Cookie/重放/拒绝检查；自建Web已停 |
| migration-upgrade.json | 自建合成库23旧迁移→24，旧61表摘要保持；新6表回滚/重应用也保持旧摘要 |
| contracts.txt | 本工作区独立jsonschema环境，OpenAPI1.0.5与59公共样例通过；新C四份context在PHP专项逐一精确对照 |
| dependency-files.json | composer.json/lock与固定00基线逐字一致及SHA256；无新增业务依赖 |
| composer-audit.json | 实际locked audit：安全公告0、废弃包0；不把文件未变冒充审计 |
| shutdown.json | 本批HTTP进程0残留；原有05 web/queue/scheduler及PG保留，新合成库保留，其他站未触碰 |

PHP新增文件Pint及Python AST检查、git diff检查通过。文本输出仅去ANSI颜色及行尾空白，结果与计数未改。不同验证轮次分开记录，不宣称同一次命令验证所有事项。迁移干净安装另在新建专属HTTP合成库实际完成24项；升级保留场景不使用实际业务库。

复跑工具随源码提供，位于`h5-platform/tests/Feature/Acquisition/Support/`；测试库必须是本工作区独立生成且有所有权标记的合成库。Laravel配置缓存存在时拒绝运行，避免覆盖测试数据库选择。HTTP探测回环直连、仅停止自身进程组，不能拿该工具管理其他站点。

本轮没有新C用户页面、真实01/03/04消费者、新账户/付款映射、UTM、持久聚合、备份恢复或平台外联验收。C-only关闭不证明旧v1全管线关闭；GPC字段是检测结果而非原广告全链执行证明。真实地区/受众、正式告知及部署/供应商/退出/清理仍待00后续核准，Meta/TikTok没有新增HTTP平台接受或真实确认。
