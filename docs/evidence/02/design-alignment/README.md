# 02 F09 内容后台纠偏证据

> 版本边界：本目录是19c9556交付的77f270a应用证据，四项后续定向修复见[新证据包](../f09-targeted/README.md)。本目录原134图中仅分集手机明暗共8张为375宽（应用/原稿各含完整图与viewport），其余126张为1100宽；本包没有手机发布确认、候选或全局确认图。这些短屏状态由新包新增，不追溯宣称旧图覆盖。

本轮固定实现 `77f270a93857f5626a88736d6e732bb0bf26f5da`，业务契约 **1.0.5**；获批原稿 `bc5a24d63b7e23126bad9bb5519ea0e9fa8825cf`。公共最终接入 `8dc6197ecc259aedd5dd1b3e2d171b3a4e90e2e9`，正常合并，未复制公共文件。本包是02模块自测和对照交付，**不替代00接收、06组合复验或07恢复通知，F09仍开放**。

## 环境与事实

- 工作区 `/Users/hejunxiang/.codex/worktrees/h5-02-content/H5`，分支 `codex/02-content`；公共Git目录 `/Volumes/KESU/H5/.git`。`h5-foundation-v1`仍为祖先；没有重置工作区、修改原稿或复制覆盖主目录。
- 仅02环境：网站8202、PostgreSQL5602、数据库 `h5_2e3d6828b8`，自动测试用独立 `_test` 库。未接触06/07库、服务、草稿或3/11人工记录。
- 实际员工密码+MFA会话；普通运行使用真实服务。测试视频是本机生成的两秒合成视频，SRT/图片也是合成；“第8集失败”来自真实解码失败，第12集最初无视频，绝非生产伪造ready。
- `原版/`、`A Star Between Us`源素材没有改动；本轮不重新宣称62集源素材全量解码/语言人工验收。旧素材证据见02历史交接。本次未使用`成片/`，未把媒体、凭据、数据库、缓存写入Git。

## 验证结果及范围

| 证据 | 实际说明 |
|---|---|
| `tests.txt` | Content + Contracts **102项 / 1589断言**；含独立PostgreSQL进程并发、媒体权限/Range、预览零写入、显式暂停、最终原子确认、旧版本失败回滚、新迁移和真实操作者。业务结果先于最后公共纯视觉补丁，模块PHP未随该公共补丁改变 |
| `browser.json` / `browser-run.txt` | **27项**真实后台复演、零页面脚本错误；MP4/SRT分开上传、确认前零上传请求、真实失败与取消、旧绑定保持、服务端已写入但响应丢失仍留编辑、now草稿刷新、10/10/10/5、手机控件边界 |
| `pair-data.json` / `pair-run.txt` | 同状态合成35集对照；封面与Banner另实际上传候选、确认保存并从服务器重读；全局影响预览取消不写入 |
| `contracts.txt` | OpenAPI **1.0.5 / 59样例**通过；没有公共契约改动 |
| `dependency-audit.json` | 锁定依赖无advisory/abandoned；无依赖、锁文件改动 |
| `migrations.txt` / `migration-repeat.txt` | 本工作区15项迁移已运行，重复迁移无操作；新增迁移没有沿用旧13项报告 |
| `source-manifest.json` | 固定源码、原稿与截图摘要；凭据和原始媒体不纳入 |

脚本在 `h5-platform/tests/Feature/Content/Support/`：`design-alignment.browser.cjs`是功能复演；`prototype-comparison.browser.cjs`只读原稿；`visual-pairs.browser.cjs`建立专门的合成同状态数据，真实执行图片与发布动作。所有脚本核对当前工作区与端口；测试资产、会话和原文件留在`.runtime/`。重跑会创建新的合成剧目，发布过的验证剧目在退出时下架；不会清空原库。

## 截图方法与判定边界

- Chrome版本、DPR、数据状态见`pair-data.json`。桌面 **1100×1000**，窄屏 **375×812**，DPR1；同一浏览器、缩放、主题、滚动到顶部。
- 原稿读取固定`index.html`的**原始data-srcdoc**，去掉导出器外层iframe及16px画布边距后原样渲染；不修改`approved-fragment.html`、源状态逻辑或CSS。业务画布与实际后台视口相同。
- `*-viewport.png`是相同视口直接截图；无后期缩放或拼接。无`-viewport`的图保留完整长页。原稿自身容器圆角/边框及模拟说明与真实全屏环境提示的差别不隐藏。
- 主要同状态：逆光之约 / Into the Light，35集、33集就绪、0公开，第8集真实失败、第12集缺视频；文件01–35命名。排期统一2026-10-24 00:00北京时间、每天10集；发布后10/35、暂停等均实际执行。
- 原稿的播放示意/成功计时器被真实播放与服务端状态替换；增加双语、语言登记、映射修正、真实进度与失败提示属于必要真实化，逐条说明见`comparison.md`，不能当作像素完全相同。
- 不从“HTTP成功”“无溢出”推导设计一致。失败、取消、版本冲突、响应丢失的页面由`failure/`独立保留，原稿未定义的状态明确列为真实化扩展。
- 27项功能复演先固定模块行为；最后公共字体/弹窗补丁只重做受影响截图与针对性可操作检查，未将旧公共图冒充新版本。

## 新迁移顺序与回滚

既有 `2026_10_21_020001_create_content_operations`保持不变；新增：

1. `2026_10_21_020002_add_content_plan_draft_timing`：仅Content计划的草稿意图，默认scheduled。旧active/paused/定时草稿的state、next_at、launch_at、游标、版本不变；now草稿只存选择，不发布、不启用，固定日期不漂移。
2. `2026_10_21_020003_add_content_operation_actor`：仅Content操作投影的nullable姓名/类型。新记录从冻结`IdentityContext::staff()`读取真实姓名；无员工执行记系统；旧记录保持未知，不补造历史，不读Identity私表。

重复执行标准迁移由迁移账本跳过。部署必须先迁移再启动新代码。回滚须先退回对应应用版本，再逆序回滚020003、020002；仅丢弃新操作人投影/草稿意图字段，业务发布、媒体绑定、时间、公开记录、Identity正式审计保留。已采用旧迁移未修改，迁移回滚/再应用的计划不变性有专项测试；未在06/07或外部站执行。

## 复验入口与补充说明

在自己的独立工作区按 `docs/01-开发接入说明.md` 初始化；沿用 `docs/evidence/02/admin-ui-v2/README.md` 的本地账号/MFA/合成素材准备命令。设 `H5_PLAYWRIGHT_MODULE` 指向现有Playwright，`H5_CONTENT_BROWSER_ACCOUNT` 指向自己生成的私有账号，Chrome可用 `H5_CHROME_BIN` 指定。依次运行：

```text
node h5-platform/tests/Feature/Content/Support/design-alignment.browser.cjs
node h5-platform/tests/Feature/Content/Support/prototype-comparison.browser.cjs
node h5-platform/tests/Feature/Content/Support/visual-pairs.browser.cjs
```

最后一项全流程本次成功完成，`pair-run.txt`可核。之后另以`H5_PAIR_RESUME=1`执行已有补充检查分支，重拍负责人取消弹窗及片库的唯一匹配草稿（`libraryFixtureId`），并重读实际图片绑定。该分支原为先前版本脚本的最后关闭选择器修复补验而设，`pair-data.json.resume`保留该历史说明，**不表示本次全流程失败**。片库图与单剧图使用不同ID的本地合成剧，名称、总数、就绪/公开状态相同；没有修改或删除其他剧来伪造列表。新的干净环境可直接用全流程生成的唯一草稿图；重复执行存在同名草稿时须记录筛选和具体ID，不能据此断言列表像素一致。

本包直接查看的图分别为375宽手机深色分集，以及1100宽桌面发布确认、分集候选和全局确认；这些观察不表示后三者已做手机验证。原稿发布确认没有真实校验提示，实际多一条服务端检查提示并展示完整年份，差异已列C12，不以不同高度判为无差异。
