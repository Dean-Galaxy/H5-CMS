# 01-B 去敏自测证据

2026-10-31，最终源码 `06e1bc2c4b8b05c9d4fa01a19646c4f0745f62a5`，前置实现 `a9c00e83d825e464e54ce15a7ea263a87d7529c1`。

- `php-regression.txt`：本工作区 PostgreSQL 专属 `_test` 库，Identity 全目录 + Contracts/RegistrationMeasurementTest、AdminPanelIntegrationTest、RuntimeEnvironmentGuardTest；138项1406断言，9.19秒。注册结果提供方为公开接口测试替身；默认未就绪实现另由公共专项覆盖。不是05真实适配组合。
- `purpose-browser-fixtures.json`：21场景，Chrome运行真实identity.js和identity.css、全部HTTP拦截、合成身份、320×500视口。包括英文与中文窄屏/32px大字号DOM、409采用最新选择及429等待重试，不代表真实整页设计或08人工复看。未监听或访问18601，URL仅提供可信本机来源供浏览器生成UUID。
- `registration-recovery-fixtures.json`：原6类回程/普通模式兼容夹具，全部请求拦截。
- `contracts.txt`：现有OpenAPI1.0.5及59样例通过；不宣称本轮新增R1 HTTP已纳入原OpenAPI。R1公共约定由00独立补充文件管理，本轮只消费。
- `composer-audit.json`：现有锁审计，无已报告advisory/abandoned；未改依赖锁。
- `test-migrations.txt`：隔离测试库27项；本轮B没有新迁移，01-A和05-A新迁移均来自统一基线。开发库/旧人工站未升级。
- `stopped-status.json`：交付前仅本工作区正常停止，五项false；保留数据/环境。状态命令停止后返回1属预期。
- `source-sha256.txt`：12个源码文件，供00串行接收核对。

初次浏览器夹具使用非可信HTTP域名，Chrome拒绝crypto.randomUUID，导致保存显示未确认；改全拦截localhost来源后通过，未降级UUID算法。复核追加换号后清除旧选择显示、按用途标注结果/重试、409回读失败不假称已回读。最终证据对应这些修正后的源码。先前137/1388及137/1392是增补故障分类测试前结果，不混作最终138/1406。

复跑：根目录使用scripts/dev.py的test隔离配置，选择上述四组PHP路径；浏览器脚本分别为h5-platform/tests/Feature/Identity/Support/registration-purposes.browser.cjs、registration-r1.browser.cjs。脚本支持H5_PLAYWRIGHT_TEST与H5_CHROME指定已安装工具；所有请求由夹具处理，无实际业务网络。

不包含环境文件、Cookie、真实身份、签名邮件URL、密码、CSRF值、密钥、媒体或数据库；这里的合成HTTP成功不是05实际存储、真实邮件送达、生产采集或广告外发证据。

00随后提供08固定设计文档ffde38457540ba567512b7b8a2cc8e4269eee531，仅只读对照，追加06e1bc2：本机测试条款标签/新标签页阅读保留草稿、版本及内部码折叠安全详情、逐项草稿与真实状态/采用最新值、成功后收起创建按钮、使用既有04主动隐私入口/状态钩子。未合08分支或改04文件。138/1398与17+6是对照补齐前结果；最终138/1406及21+6覆盖补齐源码，实际08视觉终审仍待00提供组合画面。
