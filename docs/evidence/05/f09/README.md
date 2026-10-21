# 05 F09：A01–A03 修正与对照证据

2026-10-21。模块交付候选，**待 00/08/06 复核，不是 F09 关闭或 07 恢复通知**。旧 3/11 人工结果及其他工作区数据未修改。

## 固定版本与条件

- 应用起点：`be7bb4f7c5585418883b777ce2e6f2676690f5ff`；正常同步 00 退回记录 `ca07803`，随后正常合入公共 `0311ed67d651665edb2fd22fdf65bb56ca16ae4e`、`b2f2f0042d9a5b6830a7ef8c1c313b3c6cb41881`、最终精确补丁 `45785d827a49dbf0f6793369ebceb5a02908a12e`，未复制公共实现。
- 最终应用与测试源码：`8d3b444d338a5b76ec2a6f0af0737f6421f92101`；主要修复 `47c580c8bcf26468bf5bc9f1ac9b1aff69bb24ce`。契约仍 1.0.5 / admin-ui-v1。交付文档提交不改变该应用源码。
- 唯一设计：`bc5a24d63b7e23126bad9bb5519ea0e9fa8825cf` 的 `approved-fragment.html` P:213、原始 `index.html`；文件摘要和与固定提交的逐字核对见 source-manifest.json。08 审查 c5454d7 已由 00 单文件接收，未合并 GitHub 独立根。
- 自身环境：05 独立 8205/5605；APP_ENV=local，H5_LOCAL_MODULES=acquisition，发送 disabled。自己的 Owner 和普通投流测试账号，真实密码/TOTP 登录；账号只在忽略的私有文件。没有访问或修改 06/07 环境、素材、数据。
- 浏览器 Chrome 155.0.8059.85 / macOS，DPR=1，缩放100%；桌面1280×900、窄屏375×812，两种视口各有 light/dark。同一轮拍原型与实际相同标签，窗口滚动回顶部，保留完整原始截图，不裁切、不拉伸、不拼接。
- 原型直接打开未修改 index.html：导出页有16px外边距，iframe实际宽度为视口减32px；实际应用铺满视口。此导出装饰不被复制成业务留白。因此不能按绝对屏幕坐标进行逐像素差异判断；按同标签的信息层级/控制形式和实际CSS规格核对。原型有“全局设置”，本候选未接02独立设置页而隐藏该入口，属于公共组合待验项。

## 问题映射及初审结果

| ID | 原稿要求 | 本轮实现/操作事实 | 对照证据与结论边界 |
|---|---|---|---|
| A01 | P:213：选剧、平台、像素在新增弹窗，四栏 | 链接列表不再常驻表单；新增链接→共享dialog；具体链接行→对应对象编辑；保存失败留窗，取消须确认；唯一主标题交workspace，原权限/版本保留 | links配对图核对四栏/列表入口；new-link、edit-link、rejected-save为actual-only。原稿仅文字规定弹窗，未画字段排列，不声称内部像素匹配。浏览器验证选剧/平台/像素均在同一弹窗、错误保留、放弃恢复和焦点返回 |
| A02 | 启停、分配在对应资产内，无第五标签 | 像素行→编辑/启停dialog；Owner行→该资产分配dialog，保持像素/链接上下文；普通员工不渲染分配dialog且后端拒绝；旧assignments URL回退links | pixels配对图核对四栏；pixel-editor/assignment为B类真实化。实际勾选→放弃不变，勾选→保存→重开确认，再明确恢复原授权；不借弹窗变更授权规则 |
| A03 | 付款汇总交易类型/笔数/USD，发送说明结果/失败原因 | 汇总直接表格，去掉额外外框和重复说明；保留测试/真实环境、再次订阅、整数美分格式化与统计口径。发送仍脱敏聚合，无用户明细 | payments配对为相同两类、两行、笔数2/1及总额6/12。实际多环境列及USD后缀是必要真实化；delivery原稿仅说明，实际空发送记录不造成功。未测真实平台归因/ROAS |

16组配对（32张）及20张实际扩展状态共52张，详见 [comparison.csv](comparison.csv)。文件名含源码短SHA、视口/主题、场景、approved/actual。links/pixels/deliveries原稿没有实际列表数据，因此只能作同功能状态的结构对照，不能宣称列表字段和行高与不存在的原稿逐像素一致。

## 数据、步骤与实际验证

`Support/f09-fixture.php` 是显式开发夹具，仅自己的匹配 local库、preview邮件、loopback、Acquisition已启用、disabled发送才允许。创建一条测试像素、一条停用的合成旧链接及三条 **is_test=true 的合成汇总输入**；不写 Content/Billing，不产生订单/会员，不派发事件。两笔300美分首订、一次1200美分续费只用于视觉行数/金额对照，**不是03核实付款或真实平台事件**。页面保留“测试”列。其他两条历史本地模拟像素未清理；像素列表三行与原稿文字占位不同，按B类记录。

1. Owner登录→投流管理：四栏，无可见常驻编辑表单；连续方向键/End/Home后焦点保持。
2. 新增链接→填一项→Esc→继续编辑保留；取消→保存并继续触发缺字段错误仍留窗；放弃→关闭并恢复服务器模型/返回入口焦点。
3. 像素行编辑→修改→放弃→重开值不变；正常保存关闭回列表。
4. 像素行分配→读取人员→修改→放弃不持久化；修改→保存→重开重读授权，再明确恢复初始授权。无新增一级标签。
5. 刷新和浏览器Back保留合法标签；assignments旧URL回退；普通员工真实MFA登录不能看到分配内容。
6. 指定合成链接行编辑恢复该对象；查看汇总显示测试/首次订阅2笔6美元、续费1笔12美元；发送仍关闭。
7. 同条件拍四栏；另拍新增/行编辑/校验失败/像素编辑/分配弹窗。窄屏表格允许组件内横向滚动，未把“无整页溢出”当设计通过。

证据：

- `browser.json`：最终8d3b444上 **28项**实际浏览器行为通过，无JS错误，截图同次生成。
- `final-desk-tests.txt`：最终8d3b444上页面专项 **9项/99断言**，含四栏、共享dialog、未知/越权对象、旧URL回退和金额。
- `tests.txt`：核心修复47c580c、公共0311ed6上 Acquisition全量＋AdminUiTest **51项/628断言**。后续公共修改为选择项/短注/间距，模块后续仅视图/浏览器夹具，按00要求补受影响页面/浏览器；未冒称在8d3b444重跑全部业务。
- `contracts.txt`：OpenAPI1.0.5/59样例；后续公共补丁未修改OpenAPI。`migrations.txt`13项Ran；无本轮新迁移。新同步的Content既有迁移只应用于05自己的库。锁定依赖审计advisories/abandoned/filter均空；本轮无依赖变更。
- `source-manifest.json`记录最终源码、原稿与截图摘要，`runtime-status.json`为自身运行状态。HTML、会话、Cookie、MFA/密码未进入证据。

## 复现与尚未对照项

从自己的独立环境初始化身份，不复制05的.env/数据库/账号。先确认本机Web进程实际加载H5_LOCAL_MODULES=acquisition；对已运行服务重复start不会更新其环境，需自身停止/重启。准备自己Owner与普通投流MFA身份，填各自email/password/totp_secret私有JSON；启停仍用scripts/dev.py。

```sh
php h5-platform/tests/Feature/Acquisition/Support/f09-fixture.php
H5_ACQUISITION_PREVIEW_URL=http://127.0.0.1:8205 \
H5_ACQUISITION_PREVIEW_ACCOUNT=.runtime/acquisition-preview-account.json \
H5_ACQUISITION_OPERATOR_ACCOUNT=.runtime/acquisition-operator-preview-account.json \
H5_ACQUISITION_CAPTURE_PREFIX=8d3b444- \
H5_ACQUISITION_SCREENSHOTS=docs/evidence/05/f09/screenshots \
H5_PLAYWRIGHT_MODULE=/path/to/existing/playwright \
node h5-platform/tests/Feature/Acquisition/Support/admin-ui-smoke.cjs
```

夹具只供显式本机验证；不能用于正式数据或作为付款来源。模拟汇总不运行03付款链路。为了保持历史付款不重写，本轮未改变来源/快照/发送服务。

原稿未定义的内部字段、错误/长名单、详细发送记录及真实数据密度只标必要真实化；校验失败沿用框架字段错误（当前可出现英文），没有为了贴图伪造成功。全部真实剧目/平台组合、所有行数/分页与浏览器、完整跨模块外壳一致性未在本轮视觉逐项覆盖。00/08需复核本映射，06需在新的组合候选按其清单复验；F09未关闭，07继续暂停。D05、网站衔接、Meta/TikTok外部联调及广告外发仍未放行。
