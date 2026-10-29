# 01 F09 公共稳定版本接入后的最新证据

日期：2026-10-21。状态：01 定向修正和复验已完成，待 00 / 08 / 06 接收；**F09 仍开放，07 继续暂停。** 本目录替代旧 f09 目录作为本轮最新画面，旧业务结果及截图按其固定版本保留。

## 固定基线与范围

- 正常合入公共固定点 `8dc6197`（含 6f622b1、2e6dd1b）；模块实现固定 `4cdbdcd0fc9645304bd2b99c671656650d9a41c2`，完整 SHA 和源文件摘要见 source-manifest.json。此前邮件审计补修 e5d33c2 / 543f629 已包含；契约 1.0.5 不变。
- 模块唯一新增生产改动：员工账号、操作审计各自把表格容器及紧随说明包装到一个 `div.h5-table-with-note`，作为 tab panel 的一个子项，避免 panel 18px 与 note 9px 叠成 27px。不对表格添加负边距，不复制公共样式。
- 公共字体、行高、图标、控件和弹窗滚动直接消费固定版本。本轮未改公共文件、密码、权限、迁移或依赖；没有接触主工程及 06/07 数据与进程。
- 01 独立工作区 h5_2396db4f70，网站8769/数据库6269，专属测试库后缀_test。启动时五项运行检查全通过；开始无员工，临时创建两名合成员工，负责人以真实密码和 TOTP 登录。所有邮箱为 example.test；没有用户凭据、真实邮件、支付或外发操作。
- 原稿 bc5a24d 的 index.html 与 Git 内容逐字一致；提取的 data-srcdoc 也逐字一致，不改原稿数据或导出继承。排除外部导出页16px空白，保留原稿自身1px根边框。
- 与上轮同一本机 Chrome 155，DPR1、缩放100%、UTC浏览器，截图未裁剪。桌面亮/暗1280×900、窄屏亮375×812；打开管理前浏览器会为点击入口横滚表格，因此不对背景表格横滚位置作像素结论。

## 配对图索引

同视口/主题/页面状态，真实业务数据与原稿示例不完全一致。

| 场景 | 原稿 | 实际 |
|---|---|---|
| 桌面亮色 / 员工列表 | [原稿](4cdbdcd-desktop-accounts-prototype.png) | [实际](4cdbdcd-desktop-accounts-actual.png) |
| 桌面亮色 / 新增空表单 | [原稿](4cdbdcd-desktop-create-prototype.png) | [实际](4cdbdcd-desktop-create-actual.png) |
| 桌面亮色 / 管理启用的内容运营 | [原稿](4cdbdcd-desktop-manage-prototype.png) | [实际](4cdbdcd-desktop-manage-actual.png) |
| 桌面亮色 / 有记录审计 | [原稿](4cdbdcd-desktop-audit-prototype.png) | [实际](4cdbdcd-desktop-audit-actual.png) |
| 窄屏亮色 / 员工列表 | [原稿](4cdbdcd-mobile-accounts-prototype.png) | [实际](4cdbdcd-mobile-accounts-actual.png) |
| 窄屏亮色 / 新增空表单 | [原稿](4cdbdcd-mobile-create-prototype.png) | [实际](4cdbdcd-mobile-create-actual.png) |
| 窄屏亮色 / 管理启用的内容运营 | [原稿](4cdbdcd-mobile-manage-prototype.png) | [实际](4cdbdcd-mobile-manage-actual.png) |
| 窄屏亮色 / 有记录审计 | [原稿](4cdbdcd-mobile-audit-prototype.png) | [实际](4cdbdcd-mobile-audit-actual.png) |
| 桌面暗色 / 员工列表 | [原稿](4cdbdcd-dark-accounts-prototype.png) | [实际](4cdbdcd-dark-accounts-actual.png) |
| 桌面暗色 / 新增空表单 | [原稿](4cdbdcd-dark-create-prototype.png) | [实际](4cdbdcd-dark-create-actual.png) |
| 桌面暗色 / 管理启用的内容运营 | [原稿](4cdbdcd-dark-manage-prototype.png) | [实际](4cdbdcd-dark-manage-actual.png) |
| 桌面暗色 / 有记录审计 | [原稿](4cdbdcd-dark-audit-prototype.png) | [实际](4cdbdcd-dark-audit-actual.png) |

另有 375×450 的实际短屏检查图，无批准的对应短屏流程稿，不作为原稿像素配对：[新增表单滚动](4cdbdcd-short-create-scrolled-actual.png)、[管理](4cdbdcd-short-manage-scrolled-actual.png)、[未保存提示](4cdbdcd-short-unsaved-actual.png)。管理内容可完整显示，scrollTop=0；新增实际滚动138px。

## 测量、验证与剩余边界

| 项目 | 当前证据与结论 |
|---|---|
| 表后说明 | 桌面、窄屏、暗色下 accounts/audit 实际 noteGap 均9px；原稿员工列表同为9px。审计原稿没有同样的附加说明，不推定其像素一致 |
| 表格起点 | 桌面亮/暗原稿y=227.484375，实际226.484375，差1px；两侧h1行高30.6667px。旧“约4px”偏移问题由公共排版修复，剩余根边框位置差明确保留；未给table减4px |
| 弹窗内容 | 三种场景新增首字段距标题底20px，管理首块邮箱距标题底18px；header完整覆盖X高度，首块不被X遮挡。桌面两侧宽640，实际y62/原稿y63；窄屏实际355/x10/y18，原稿353/x11/y19 |
| 短屏 / resize | 真实页面从桌面打开后改375×450，新增/管理X高42且完整可命中；滚动区从y78开始、X底y78，新增可滚动且点击X关闭。默认gap5的真实未保存提示首段也在X以下，放弃可关闭；见short-screen.json |
| 真实化差异 | 列表均两人、启用且有MFA，但实际邮箱多一行，列宽/行高不同；管理邮箱块和18px角色前间距使实际桌面比原稿高39.6875px；新增真实密码帮助与交付/MFA说明替代演示占位文字，桌面窗口比原稿矮4px，窄屏高14.59375px。这些差异留待设计接收，不隐去真实字段或声称完全相同 |
| 审计数据 | 真实29行，原稿1行；有真实本轮登录/权限修改及保留的历史合成审计。只比较四列层级及显示状态，不宣称全表高度密度相同，不清空审计或补造历史姓名；部分旧夹具已清理，显示账号不可用 |
| 公共导航 | 实际3项、原稿5项，窄屏导航与正文起点不同；不为了拍图开启05。根边框/容器高度/账号入口/导航图形等公共差异仍按原有边界留待组合复核 |
| 定向业务测试 | 最新实现上 StaffAccountsTest 14项、112断言通过，0.75秒，含真实预览审计；page-tests.txt。更早80项全身份测试仅适用于既有记录版本，不冒充本轮重跑 |
| 实际行为 | behavior.json 8项通过：无效密码服务器拒绝和草稿保留、继续编辑、放弃/焦点、20→18间距切换、真实角色/停用保存与焦点、真实审计、请求编号、刷新保留标签。截图前已通过真实UI恢复运营启用；storage-result.json确认版本3且失败创建未落库 |
| 探针修正 | 首次测量管理内容误选了角色字段而非前置邮箱，改为按DOM顺序选首块；短屏首次读数早于ResizeObserver完成，改为等待几何稳定后测量。未因探针修正改变生产代码 |
| 清理 | cleanup.json：两名临时员工/其会话剩余0；本地凭据和浏览器会话文件删除。stopped-status.json五项false；其他数据与审计保留 |

未覆盖：空审计同稿对照、极长员工名实际记录、窄屏暗色、外部设备、真实邮件/支付。登录/MFA/个人资料本轮无新版批准画面，不新增像素结论。当前无需用户申请账号或采购。

00 接收固定提交后，由 08/06 在组合版本复核后决定设计接收范围和 07 恢复。本轮仅补齐公共稳定版本接入证据，不自行关闭 F09。
