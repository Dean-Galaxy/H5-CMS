# 06 F09 可复演顺序

只在00指定候选的新独立工作区执行。本文不是允许覆盖正在验收环境；禁止操作8206/8303或复制同事.runtime/.env。以下实际06端口为8377/5877/8378；新工作区需另选空闲端口。所有账号/素材为本机生成。

1. 用根`bootstrap.py`/`dev.py setup`建立锁定依赖、独立数据库/用户/密钥及端口。启动后先15迁移、全量Feature、`scripts/ops/run_acceptance.py tests/Feature/Contracts tests/Integration tests/Security`、`.runtime/contracts/bin/python scripts/validate_contracts.py`和Composer锁审计。此步骤会重置**自己的_test**，必须先于账务夹具。
2. 普通服务显式本机配置：

```sh
H5_LOCAL_MODULES=acquisition H5_LOCAL_US_TEST_REGION=true ACQUISITION_TRANSPORT=disabled ACQUISITION_EXTERNAL_ENABLED=false python3 scripts/dev.py start
```

已运行旧进程时先在同目录`dev.py stop`再启动，环境变量不会追溯改变旧进程。不得把公共站设置local来使用这些夹具。

3. PHP用根工具配置的8.4。运行`h5-platform/tests/Feature/Content/Support/prepare-browser.php`，生成自己的随机Owner+MFA及2秒蓝色视频/图片，文件0600。CLI与Web保持同一独立数据库配置，执行05工具时显式`H5_LOCAL_MODULES=acquisition`。此为夹具准备，不代表真实员工首登MFA绑定通过。
4. 先运行Content真实流程，生成35集样例供后续范围/观众检查；然后原型与实际配对。使用机器上已有Playwright，绝不为验收修改根依赖锁：

```sh
export H5_PLAYWRIGHT_MODULE=/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright
python3 scripts/ops/f09_browser.py content
python3 scripts/ops/f09_browser.py release-range
python3 scripts/ops/f09_browser.py content-pairs-all
python3 scripts/ops/f09_browser.py content-prototype-all
node h5-platform/tests/Browser/f09-content-extras.cjs
node h5-platform/tests/Browser/f09-audience.cjs
```

工具仅接受本工作区.runtime绑定和固定候选的业务树。release-range先要求真实35/35下架完成态，其他8组是显式响应夹具。audience随后真实重新上架并在最后恢复35集公开/finished状态；不是无写入脚本。Content每次flow/pairs都会新建自己的合成剧，不用于生产或复用真实素材。不要仅为截图无故重复全套。

5. 05调用自有`h5-platform/tests/Feature/Acquisition/Support/f09-fixture.php`准备TEST像素/链接和is_test汇总。Owner直接复用**本工作区**Content生成的Owner文件。通过真实员工管理UI新增随机投流账号，把其本机随机凭据写到`.runtime/acquisition-operator-preview-account.json`（0600，email必须`h5-06-acq-…@example.test`），不可复制其他工作区员工。

普通正式流程须本人配置MFA。若仅为明确的本地自动化夹具，可以显式执行`H5_LOCAL_MODULES=acquisition php scripts/ops/f09_prepare_mfa.php --rotate-synthetic-mfa`：严格要求本机local/preview、自己的DB、外发关闭且指定合成投流账号。它会重置该夹具MFA并私密保存，不输出secret；不能作为标准首登绑定验收。不要将TOTP、恢复码或二维码输出到日志/截图。

```sh
python3 scripts/ops/f09_browser.py acquisition
python3 scripts/ops/f09_browser.py acquisition-errors
python3 scripts/ops/f09_browser.py acquisition-dirty
node h5-platform/tests/Browser/f09-acquisition-prototype.cjs
node h5-platform/tests/Browser/f09-identity.cjs
# 最后停用临时投流账号和闲置预览Owner，保留正在使用的Content Owner：
node h5-platform/tests/Browser/f09-retire-test-accounts.cjs
```

不要连续快速登录规避限流；正常等待服务端提示时间。05网络测试在请求送达前abort，Content74项另验证服务端实际提交后丢响应；这两种证据不能互换。当前本轮两临时账号已退役，重跑涉及operator时需重新准备，不擅自复活旧凭据。

6. 所有重置型测试之后，准备03严格账务环境；此时选择独立空闲端口8378，所有命令显式带端口，禁止工具默认8303：

```sh
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py seed --port 8378
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py start --port 8378
```

03浏览器从自己的`03-admin-browser-server.json`读取8378，不通过另一个URL变量覆盖。执行：

```sh
node h5-platform/tests/Feature/Billing/Support/admin-browser.cjs
node h5-platform/tests/Feature/Billing/Support/admin-browser.cjs --f09
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py verify-ui --port 8378
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py stop --port 8378
```

一次default写价格/备注，再F09设计检查，最后核对并停止。本轮历史实际顺序F09先于default，已在报告和证据标记，不调整旧截图时间。新轮不得重复default造成版本/备注累加，也不得在此后跑RefreshDatabase。

7. 备份独立恢复：`python3 scripts/ops/backup_restore.py --with-unpaid-accounts`。只建自己的恢复源副本和独立PG，清单绑定同11对象+3未付账户；testing FakeGateway不等官方。`python3 scripts/ops/f09_runtime_snapshot.py before`和停启后的`after`记录实际进程和内容摘要，普通服务停止/启动仍使用上面的显式local配置。
8. 保存实际代码SHA、脚本SHA、原型SHA、浏览器/视口及运行顺序。仅复制脱敏JSON/原图到docs/evidence；密码、签名URL、SQL、媒体、邮件不进Git。已运行服务不能直接切版本；00新候选到达后先停**自有**服务再切代码/迁移/启动，受影响结果另立新版本目录。
