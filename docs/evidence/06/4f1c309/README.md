# 06 证据索引

2026-10-07，业务起点 **4f1c3097a38309e88c9bea5dcb1b6992f9f007d3**、契约1.0.3；06完整组合固定提交 **88c2feff53b3f962ab85bc4a3799463754d54759**。本目录名字表示业务起点，不代表所有工具仍在4f1；每项见下表。后续纯文档提交不改变已验应用或测试。

| 文件 | 实测版本/内容 |
|---|---|
| combined.txt | 88c2fef；全部Feature+Integration+Security，283项/2950断言，94.03秒 |
| contracts.txt | 88c2fef；OpenAPI1.0.3及57样例 |
| player-state.txt | 88c2fef；16个状态替身测试，独立于真实浏览器 |
| assets.txt | 88c2fef；hls.js锁定1.7.3及SHA校验，不联网 |
| security-http.json | 88c2fef；18个真实HTTP检查通过；另记local Host=200的观察项，不视为公网通过 |
| runtime-probe.json | 88c2fef；8个环境启动分支、工作区绑定、非超级DB角色；staging debug可启动是部署阻断项 |
| backup-restore.json | 88c2fef；47表、42媒体摘要、第二PG实例恢复停启、测试付款/退款语义核对、D03过期挂起；私有备份未提交 |
| restart.json / restart.txt | 88c2fef；本机网页/队列/scheduler/PG停止和启动检查；不是云主机重启 |
| audience-http.json | 4f1业务代码；8个真实HTTP场景，preview邮件与独立Cookie jar |
| composer-audit.json | 4f1与88c2fef锁文件相同；本次实时Composer公告审计无公告和弃用 |
| browser/*.json / *.jpg | 88c2fef；见 [浏览器方法](browser-method.md)，不代表真机 |
| baseline-501-tests.txt / baseline-4f-tests.txt | 历史版本各自的212/1940与279/2905，不能据此证明88或未来候选 |
| initial-migrations.txt / upgrade-migrations.txt | 独立空环境501迁移和4f增量迁移；无其他工作区数据 |
| known-secret-scan.json | 88c2fef；当前工作区已知密钥/密码不出现在跟踪文件和本机服务日志，有限范围扫描 |
| manifest.json | 实测版本、代码一致性、工具与证据文件SHA-256清单 |

终端输出仅去除ANSI颜色和行尾空格；原始输出保留在私有运行目录，不修改测试内容或结果。

生成报告前已检查本目录无真实秘密、原始媒体、付款凭据或邮件预览。数据库/日志的私有原件仍在当前任务的`.runtime`，本目录只保留脱敏摘要与用例输出。

本地运行使用同一worktree的独立环境。全套命令：

```sh
python3 scripts/ops/run_acceptance.py tests/Feature tests/Integration tests/Security
.runtime/contracts/bin/python scripts/validate_contracts.py
python3 scripts/install_browser_assets.py --check
node --test h5-platform/tests/Feature/Audience/player-state.test.cjs
python3 scripts/ops/runtime_probe.py
python3 h5-platform/tests/Security/live_probe.py
python3 scripts/ops/backup_restore.py
```

不能默认公开该环境；外部staging条件、官方测试和真机仍见 [验收报告](../../../06-验收报告.md) 与 [部署手册](../../../06-部署与恢复手册.md)。
