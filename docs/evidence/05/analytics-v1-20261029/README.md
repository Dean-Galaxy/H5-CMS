# 05 analytics-v1 本地交付证据

2026-10-29。核心 `b550b1a0300a8fd26ab522533cf873f56d2a13f3`；最终权限兼容增量 `ee866fbb7b425a1dff368f6f906c2a26d47ace54`（正常接入00的4c74195组合接线）。最初正常合入00基线 `d0c3180fdbcea5b84fac4c08e1bc79dc7531df97`（含01真实身份、02内容、03真实Reader/可靠财务事实及微秒修复、04点击客户端）。业务1.0.5、附加analytics-v1。此前h5-audience-v1/1.0.2仅是历史起点，未回退标签。

## 实际验证

| 验证 | 实际结果 | 证据 |
|---|---|---|
| 05全部+附加契约 | 78项 / 1035断言通过 | module-tests.txt |
| 启动环境/公共基础/只读端口 | 19项 / 402断言通过 | environment-contract-tests.txt |
| Chrome实际网站 | 20项通过，零外部请求、零JS异常 | browser-result.json |
| 模块与运行绑定 | 真实01目录、02可投放目录；Acquisition显式local启用，广告disabled | runtime-status.json、services-status.json |
| 迁移 | 本模块050004与03新增200900均Ran，无修改既有迁移 | migration-status.txt |
| 公共契约样例 | OpenAPI1.0.5及59样例通过，独立venv按锁定版本安装 | contract-validation.txt |
| 统一组合专项（未全绿） | 11过/3失败331断言，已报告00/06 | combined-partial.txt |
| 依赖 | 当前composer.lock安装后audit无advisories/abandoned | composer-audit.json |

单元/HTTP测试包含：越权与撤销、匿名/注册前后/换号、退出迟到付款与真正撤回、固定快照/7天精确边界/普通不覆盖、微秒事实时同意、同付款双去重、退款100→300→0版本纠正、临时数据库savepoint失败恢复、两平台加密匹配到最终HTTP替身报文、失败重试/并发lease及I/O不占事务、正式/测试隔离、采集422/413/429/503安全诊断、缺依赖未知与0分母、真实标题/分集观看卡点与首帧/卡顿、90天有界清理。

Chrome实际旅程从/r合成Meta点击进入，通过04真实隐私表单同意，独立landing故意慢1.5秒，来源后才投递入口事件；播放本工作区ffmpeg生成并经02处理/发布的12秒视频，playing+时钟前进才开始，完整播放产生完成与约11秒有效观看。503故障用明确本地响应注入，刷新补发同ID，不是平台故障。撤回后停止采集、来源revoked、必要账务计数保持；新浏览器TikTok仅广告同意有匹配但无分析。员工真实登录+MFA后复看后台。浏览器没有创建订单、付款或权益；付款组合由真实01/03/04/05服务加明确FakeGateway在独立测试库证明。

截图均为合成本地样本：桌面1440×1040、手机390×844、短屏375×500及公共暗色主题。手机长图出现浏览器拼接绘制异常，最终使用实际视口及滚动后的剧集截图；未把异常长图当视觉通过证据。08统一设计接收、00/06最终组合另验。04后续隐私编辑草稿/反馈修补不属于本固定源码候选，接入后应重验受影响界面。

## 复演

在05独立工作区根目录操作；本地环境与测试库由scripts/dev.py绑定，不复制其他.env/数据/媒体。

```text
python3 scripts/dev.py status
python3 scripts/dev.py start
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Acquisition tests/Feature/Contracts/AnalyticsContractsTest.php
php h5-platform/tests/Feature/Acquisition/Support/analytics-fixture.php
H5_PLAYWRIGHT_MODULE=<本机Playwright模块路径> node h5-platform/tests/Feature/Acquisition/Support/analytics-smoke.cjs
python3 scripts/dev.py stop
```

PHP使用8.4；本机路径`/opt/homebrew/opt/php@8.4/bin/php`。浏览器脚本仅允许本地8205，夹具/状态工具强制05自有8205/5605数据库和external-off。自己的预览账号/MFA、合成媒体及浏览器输出均在私有`.runtime`，不进入Git。测试helper读03表仅为不变性断言，05生产实现只经BillingAnalyticsReader/PaymentSettled消费事实，不读写Billing表。

来源开关`H5_LOCAL_MODULES=acquisition`、`ACQUISITION_ANALYTICS_ENABLED=true`；本地测试地区`H5_LOCAL_US_TEST_REGION=true`明确不是生产地区核验。广告`ACQUISITION_TRANSPORT=disabled`、`ACQUISITION_EXTERNAL_ENABLED=false`、`ACQUISITION_PLATFORM_REVIEWED=false`。不改变默认公共提供方，不替换已接模块、不在非local/testing加载Acquisition。

本次没有Meta/TikTok真实账号/凭据/平台请求。现有HTTP替身返回api_accepted只证明适配器响应处理，不是官方HTTP接受，更不是平台匹配、归因或ROAS。官方核对依据见[Meta官方集成fbc构造](https://github.com/meta/facebook-for-woocommerce/blob/main/facebook-commerce-pixel-event.php)、[TikTok点击ID](https://ads.tiktok.com/resources/help/article/tiktok-click-id?lang=en)。

06旧helper已由00/06改为先独立landing并正常接入4c74195；最新专项11过/3失败：两条把Worker.run的持久处理数误当外发数（实际1为blocked、无HTTP），一条退出后第二账号签名验证401（CombinedTestCase:99，首账号链路已过）。确定断言已交00/06按归属复核，未自行改06/01文件，未将专项写全绿。05自己的真实Providers/点击端到端与权限兼容均通过；最终统一组合由00/06执行。本地源码已交00，GitHub上传/标签回读由00统一完成，未将本证据称作远程备份。

权限兼容由00正式接收：投流同事分析范围沿用AssetAccess的自己或当前获分配链接；撤销他人分配立即失权，自有不因冗余分配撤销消失。新查询、财务、诊断和维度使用同一对象范围，空范围不回退全站。
