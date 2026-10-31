# 04 direct-test-1购买页与受控提示交付

2026-11-01。起点`f4258a01deb39151cd032e2733492e156123e7be`正常合并为`5a862d0fd8f69d0a0775aae001b42dec0e33faf2`，保留前批修复。源码 **`a9f0d52516afc4d84672551d422699ad63610582`**，仅9个04归属文件；契约direct-test-1，追加geoip-ready-1页脚署名。无迁移/依赖/开关/旧环境变更。

- 已验证观众实际GET显示可提交新购Offer时，现有服务端draft才保存`/membership`与UTC rendered_at；游客、未验证、HEAD不记页面证据。沿原账号/价格检查并在首次过期报价拒绝后，现有snapshot经CheckoutAttribution/capture传入CheckoutPageContext，再固定在原attempt来源里。重复/未知重试不重捕、不补同意；API直建单和恢复入口不改。
- capture仅接受Origin匹配当前同站origin且Fetch-Metadata无冲突；只有same-origin Fetch-Metadata或匹配Origin至少一项存在才捕获。冲突/都缺不捕获，Referrer及浏览器提交URL不作证。原CSRF/对象/邮箱校验不放宽。旧draft无页面证据、缺同意、端口默认null或异常均保留原合法购买；有原合法来源时保原6字段，不伪造新context。付款/幂等key/回程沿用原实现。
- 现隐私组件读取05约定`advertising_test_only=true / analytics_available=false`后，显示中英“仅受控广告测试/分析不运行”说明，分析选项取消勾选并禁用、发送及存储分析缓冲停用；广告仍独立显式选择/撤回，提交analytics强制false。不改C/R1或引入SDK/身份关联。旧context无新字段时沿用原模式。
- 网站现footer仅当`geoip.driver === dbip_lite`显示现有small样式普通固定链接`IP Geolocation by DB-IP` → `https://db-ip.com`，disabled/缺配置不显示，无JS/新布局。01配置未并，测试只显式config，不实现lookup。

验证均在04自有独立`_test`库，真实Audience/Identity/Billing＋明确外部付款测试gateway；AttributionService/capture为专项测试桩，**05真实组合未验，非官方广告/付款结果**。

| 已完成检查 | 结果 |
|---|---|
| CheckoutAttributionUiTest＋受影响BillingUiTest/AnalyticsBillingTest/TrackingUiTest | 31通过/352断言；新增8例涵盖可提交draft、HEAD/无头/跨站、旧draft、缺同意/异常/默认null、原attempt重试、后补同意/API不捕获、过期/换号/价格及无draft拒绝 |
| 受控中英文真实Chrome（既有离线浏览器fixture/HTTP桩） | 新增2项通过：分析禁用/零事件、独立广告允许与撤回、篡改checkbox仍不提交analytics=true |
| 原隐私直接影响范围 | 另4项Chrome通过：中文小屏、撤回迟回执、脏草稿不被GET覆盖、保存进度/失败草稿；未重跑旧全套 |
| 追加页脚条件专项 | TrackingUiTest两项26断言通过（含原双语挂载），只对新署名小项复验，未重跑旧浏览器 |
| 静态/停止 | Pint、JS语法、diff检查通过；`python3 scripts/dev.py stop`后8204/5604实际无监听，无web/queue/scheduler/公网 |

复验服务端沿根scripts/dev.py选择的独立DB配置，仅运行上述Audience四个测试文件；导出浏览器夹具需`H5_ANALYTICS_RENDER=1`。浏览器用`node --test --test-name-pattern='controlled advertising test' h5-platform/tests/Feature/Audience/analytics-browser.test.cjs`（NODE_PATH使用已装Playwright路径）。不调用全量test或重启旧站。

00接收源码后与05真实capture/ad-only provider组合核验；01 GeoIP配置/真实库、正式告知及官方资产/测试分别验。04未部署、未推送/打标签、未启测试位或改D05/采集许可/旧期限，无本人当前操作。交接及源码分提交。
