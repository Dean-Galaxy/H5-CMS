# 03 员工账务后台 UI v2 验证

本目录保留 1.0.4 基线交付证据；后续已接入 1.0.5 并解决本站头像，见 [追加验证](../admin-ui-v1.0.5/README.md)。

2026-10-21。源码固定为 `12a9d75a9eb959ead9a85baaa2c4922c0de7dc2f`，公共基线 `7a48236c4ded237c1b55b57cfa43e4c52a9f2ee6`，业务契约 1.0.4 / 内部 UI 契约 admin-ui-v1。本目录仅含脱敏证据与合成测试页面截图，没有账号口令、MFA 密钥、Cookie、数据库、正式素材或环境文件。终端记录仅去除颜色控制码及行末空白，测试结果未改写。

| 验证 | 证据及结论 |
|---|---|
| 原账务与组合、安全回归 | [core-tests.txt](core-tests.txt)：112 项 / 2396 断言 / 10.97 秒；在公共 UI 合并前执行，排除新增 Desk/USD 用例，覆盖账务核心、Integration、Security。其后账务核心实现未改动 |
| 最终专项 | [focused-tests.txt](focused-tests.txt)：25 项 / 509 断言 / 0.99 秒；在焦点修复及最终页面完成后执行，覆盖 BillingDesk、BillingUsdPrice、公共 AdminUi |
| 真实 Chrome | [browser.json](browser.json)、[完整执行记录](browser-run.txt)：29 项通过，四类员工均真实密码和 TOTP 登录；美元保存/刷新、输入失败与离开选择、键盘、375 像素窄屏/暗色、备注持久化及权限直达拒绝 |
| 浏览器操作后的数据库核对 | [browser-storage.json](browser-storage.json)：只读事务 8 项通过；价格精确存为 1234/321，只新增一个版本；两笔原订单、订阅、付款、权益、outbox 的整行摘要不变，客服备注仅一次 |
| 契约 | [contracts.txt](contracts.txt)：OpenAPI 1.0.4 / 57 样例通过；保留已有 LibreSSL 环境提醒，未据此声称外部 TLS 验证 |
| 启动、迁移和依赖 | [startup.txt](startup.txt)、[status.json](status.json)、[migrations.txt](migrations.txt)、[dependency-audit.json](dependency-audit.json)；03 普通环境 8203 / PG5603 就绪，原迁移均 Ran，本轮无新迁移/依赖，锁定依赖审计无 advisories/abandoned |
| 格式与固定源码 | [style.txt](style.txt)、[source-manifest.json](source-manifest.json)、[verification.json](verification.json)；五份 PHP 的 Pint 通过，JS/Python 语法及差异检查通过 |

## 已发现并处理的问题

- 初次专项 30 通过、2 失败：[initial-focused-tests.txt](initial-focused-tests.txt)。客服页通用说明误称“管理会员价格”，现按角色显示说明，权限本身未放宽；最终专项全过。
- 初次真实浏览器在连续键盘操作处失败：[initial-browser.txt](initial-browser.txt)。真实 Livewire 的 loading 原生 disabled 使焦点落到 BODY，03 报告 00；00 发布 `7a48236`，03 正常合并后整套重跑通过，没有私改共享代码或跳过键盘检查。
- 浏览器脚本登录使用原控件支持的整段验证码粘贴，并按每角色至少 31 秒间隔遵守原 5 次/分钟认证限流；没有清空限流或注入已登录会话。
- 代取消在平台结果未知时已消费核实记录，页面会重读并清除旧勾选，保留备注/价格输入且返回未完成。专项使用真实 BillingAdministration/Engine 配合 testing FakeGateway 的超时事实验证，不属于官方 Stripe 验证。

## 真实与模拟边界

浏览器连接真正的 Laravel/Livewire 员工页面，使用本工作区 `h5_785df0be15_test`；预迁移核对实际数据库和端口，清空 DB_URL，HTTP 不采用 console/测试免 CSRF 模式。随机员工/观众与两笔付款仅在 testing 创建；浏览器运行时 Billing 驱动仍是 unconfigured，Stripe/SMTP 网络函数禁用。付款样例由测试网关经原结算服务进入，不是浏览器成功页造账。

默认 Filament 头像尝试请求 `ui-avatars.com`，本轮所有外部浏览器请求在发送前被拦截，外部完成请求为零；因此截图右上角可见未加载头像。00 已收到该公共问题并计划本站头像补丁，本交付不冒充已包含后续补丁。没有页面脚本错误或 HTTP 5xx。

8303 临时浏览器服务已停止；8203 普通 local 环境保持运行，未往普通库写模拟付款。Stripe 官方仍未配置/未验证，无官方 Checkout、Test Clocks、webhook 或外部发布证据。00 接收与 06 最终组合验收仍待进行。

## 复验入口

在独立初始化的工作区运行，先完成数据库测试，再创建浏览器夹具，不并发清空该测试库：

```sh
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Billing/BillingDeskTest.php tests/Feature/Billing/BillingUsdPriceTest.php tests/Feature/Contracts/AdminUiTest.php
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py seed
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py start
H5_PLAYWRIGHT_MODULE=/path/to/existing/playwright node h5-platform/tests/Feature/Billing/Support/admin-browser.cjs
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py verify-ui
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py stop
```

已有 Playwright/Chrome 即可，不新增包。`H5_CHROME_BIN` 可指定现有 Chrome；默认使用 macOS Google Chrome。`seed` 显式拒绝覆盖已有凭据，适用于新的隔离验收环境。私有凭据仅写入本工作区 `.runtime` 的 0600 文件，不能提交、复制给其他工作区或用于普通环境。浏览器脚本完整运行约两分钟，登录间隔属真实保护要求。

截图：[桌面价格](screenshots/owner-prices.png)、[订单](screenshots/owner-orders.png)、[部分退款](screenshots/owner-partial-refund.png)、[失败保留](screenshots/price-validation-keeps-input.png)、[窄屏](screenshots/owner-mobile-prices.png)、[暗色](screenshots/owner-mobile-dark.png)、[客服记录](screenshots/support-case.png)、[跨记录拒绝](screenshots/support-forbidden-case.png)。
