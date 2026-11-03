# 03 PayPal Live 离线候选证据

最终源码 `3183426b1209e9abce8ab29295037e9c2f86239d`；前段 `cd061ad950f4d774cc95ed87d15dd45730febc2e`。全部本地 HTTP Fake/合成付款、商户绑定和账号，不是 PayPal 官方结果；没有远端账户/API/云操作。独立 PostgreSQL 5603 / `h5_785df0be15_test`，没有清空开发库、实际06或周四数据库。

| 文件 | 实际范围和结果 |
|---|---|
| core-billing.txt | 首段Billing全部＋受影响PayPalSharedConfiguration/RuntimeEnvironmentGuard，445项6672断言，40.22s；在最后CLI小补及后台正式价增量之前执行，不冒充最终源码同次整组 |
| prepare-cli.txt | 最后CLI小补的新Live与旧Sandbox准备，2项30断言；无实际HTTP连接 |
| cross-module.txt | 首段付费播放/越权/缺失分析消费者，7项71断言，0.90s |
| price-admin.txt | 最终增量全部Live/Safety＋原价格后台两组，44项629断言，4.66s；含真实Livewire保存、刷新、缺主动确认/权限/MFA/版本/缺正式价拒绝 |
| price-consumers.txt | 最终增量原PayPalRuntime/BillingEngine＋付费播放/越权，87项2249断言，13.25s |
| source-manifest.json | 最终两段候选改变的应用文件SHA-256，不包含凭据、数据库或素材 |
| dependency-audit.json | 本轮锁定依赖审计无公告、无废弃依赖；锁文件未改 |

另已实际通过：OpenAPI1.0.5结构及59样例、Pint、`git diff --check`。契约验证Python的旧LibreSSL提醒保留；不把它当支付TLS客户端验证，支付仍使用已锁PHP/Guzzle。

中间失败保留为事实：旧准备命令的配置前缀被遍历变量覆盖，已修；生产配置专项最初的测试清理/TrustedHosts静态残留已隔离；精确回调最初误排除旧ValidateCsrfToken，真实生产CSRF检查返回419，已按锁定框架实际PreventRequestForgery修正。修后普通建单/取消419、Live回调到达未配置503、非精确路径404专项通过。没有放松正式环境或验签条件来让测试通过。

复验使用项目固定隔离入口，例如：

```sh
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Billing/PayPalLiveTest.php tests/Feature/Billing/PayPalLiveSafetyTest.php tests/Feature/Billing/BillingDeskTest.php tests/Feature/Billing/BillingAdministrationTest.php --no-ansi
```

实际官方签名、Live资格/正式资源、本人真钱付款及生产浏览器使用，均待00独立接收和统一安排。本轮未启动8203 web/queue/scheduler；测试需要的5603 PostgreSQL已启动并保留，未启任何Live API。
