# 00 接收 02 修复的独立证据

候选 `150972e`；00 接入 `64515a2`；main 合并 `8f2e326`。接收范围及新起点见 [00-05](../../../handoffs/00-05-02修复接收与共同起点.md)。

- `candidate-tests.txt`：原样候选 74 项 / 715 断言；`candidate-concurrency.json` 是对应 7 次子进程运行，含两次刻意注入失败。
- `browser-recheck.json` 与 `build-ui-fixture.py`：7 个独立浏览器检查。候选 JS 与表单控件保持原样，fetch 为明确虚构；只证明请求对象和页面状态，真实数据库另由完整套件验证。复跑先在仓库根目录执行 builder，再只向 loopback 暴露 `.runtime/receipt-ui`。
- `integration-first-tests.txt`：保留接入后第一次 75 通过 / 1 失败；旧游客测试仍把内容当未接入。`integration-tests.txt` 是修正后 76 项 / 743 断言；`integration-concurrency.json` 保存该轮 7 次运行。
- `contracts.txt`：原样 1.0.1 / 44 样例。正式运行发现 Health 枚举写死未就绪后发布 1.0.2；`contracts-1.0.2.txt` 为 47 样例，`contracts-1.0.2-tests.txt` 为受影响公共检查 13 项 / 99 断言。完整套件后只改健康 schema、版本和样例，未重复无关业务测试。
- `identity-http.json`：真实 HTTP 身份/会话/本地邮件预览 5 组；`content-http.json`：真实 Content 装载、地区、权限、CSRF、脚本摘要。`reception-http.py` 仅用于已按 dev.py 建立并启动的本地复核副本；不输出令牌或密码，不创建真实媒体和付款。
- `runtime-start.txt`、`migrations.txt`、`style.txt`、`dependency-audit.json`：运行、迁移、格式和锁定依赖检查。复核后临时服务已关闭，记录在 review-stop.txt。main-start.txt 与 main-runtime.json 另记主工程接收后已迁移并启动，内容表为空，未复制 02 数据。运行日志均为当时状态，不是持续在线保证。

并发 JSON 中 outcome=failed 的两项为预期故障注入，由测试确认失败阶段、子进程终止、父事务释放及无业务副作用；不代表整套测试失败。候选的原外置盘启动超时本次未复现，不能宣称已证实历史根因。

日志只移除颜色码、行末空格及末尾多余空行，失败事实保留。未提交真实密钥、账号凭据、数据库、邮件或媒体。02 自测、素材及真实后台证据保留原归属，不冒充 00 本次重新执行。
