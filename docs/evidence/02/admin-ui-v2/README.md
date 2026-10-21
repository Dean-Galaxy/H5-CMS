# 02 Content 后台 v2 本地验收证据

基于 00 固定提交 `46bd7cece7212a5db676056bc5cedac09fd2d8c3`，业务契约 1.0.5、共享界面 admin-ui-v1。`source-manifest.json` 固定本次 Content 源码与测试摘要。此处是 02 本地自测，00 接收和 06 组合验收另记。

| 证据 | 结果 |
|---|---|
| tests.txt | 354 项、5245 断言通过；真实隔离 PostgreSQL，包括新增首批现在与定时/重复确认进程竞争、手动/修改/下架竞争、素材上下文/取消/时轴/旧版本拒绝、普通编辑不得绕过替换确认、旧清单/分片/Range 许可失效 |
| browser.json | 23 项通过，无页面脚本错误；真实密码+MFA、CSRF、HTTP、文件上传与 FFmpeg/SRT 处理，未 mock 成功响应。唯一响应丢失场景有意先实际保存再断开响应，验证保留编辑及脏状态 |
| publication-desktop.png / banner-mobile.png | 实际员工后台桌面与 375 px 窄屏截图；手机重新载入并实际操作按钮，无遮罩阻断或页面横向溢出 |
| contracts.txt | OpenAPI 1.0.5 结构和 59 个样例通过 |
| dependency-audit.json | 锁定依赖审计：0 advisories、0 abandoned |
| migrations.txt | 本工作区迁移全部已应用；新增仅 `2026_10_21_020001_create_content_operations` |
| original-drama-status.json | 原测试剧总 62，公开仍 1–6，计划仍 paused/版本 6，62 视频和字幕 ready |

浏览器使用自行生成的 2 秒蓝色视频、PNG 和 SRT，创建新的 35 集测试剧；首次现在公开 10 集，提前批次推进到 20、30、35，最后下架测试剧，禁用其后续计划。测试 Banner 为停用。生成素材、员工凭据、数据库和会话不在 Git；未复制原素材或其他工作区环境。待处理期间会显示真实候选状态；字幕语言由操作人登记及确认，处理器不冒称自动识别正确语言。

## 06 全新隔离工作区重放

先由 00 指定组合 SHA。06 在自己的工作区保留独立端口、库和密钥，遵循 `docs/01-开发接入说明.md` 完成 `scripts/bootstrap.py`，再 `python3 scripts/dev.py start`。已有工作区正常合并后运行迁移，不复制 02 的 `.env`、`.runtime`、素材或账号。

在该工作区根目录执行：

```sh
/opt/homebrew/opt/php@8.4/bin/php h5-platform/tests/Feature/Content/Support/prepare-browser.php
H5_PLAYWRIGHT_MODULE=/path/to/existing/playwright \
  node h5-platform/tests/Feature/Content/Support/admin-content.browser.cjs
```

- `prepare-browser.php` 仅接受 dev.py 绑定的本机 local + preview 环境，核对实际根目录、工作区、数据库和端口。只在本库创建随机负责人/MFA，凭据存本机 `.runtime/content/preview-account.json`（0600），不输出秘密；重复执行保留该账号。FFmpeg 生成三份微型媒体，未导入业务数据。
- 浏览器脚本默认使用本工作区端口，拒绝外站或其他端口。可设置 `H5_PLAYWRIGHT_MODULE`、`H5_CHROME_BIN`、`H5_PHP_BIN` 使用已安装运行时，不安装 npm 依赖、不改锁文件。02 现有 Playwright 路径为 `/Users/hejunxiang/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright`，06 应核对本机实际路径。
- 脚本通过真实页面登录、生成独立测试剧、上传媒体；处理调用当前工作区的 Content 命令。测试结束保留已下架测试剧和停用 Banner，便于审阅；输出在本机 `.runtime/content/ui-v2/`。脚本中没有原 62 集剧目的 ID 或业务写入。
- 服务回归：`H5_LOCAL_MODULES= python3 scripts/dev.py test`。不要直接向开发库运行测试。当前浏览器脚本不证明官方支付、会员实付、真实地区或生产部署；这些仍由既有闸门单独验收。

## 许可与回收边界

视频替换只切换当前绑定，不删除旧源文件或处理结果。旧 grant 绑定旧 video asset，下一次清单、分片、MP4、Range/HEAD 请求检查时返回 STALE_VERSION；需申请当前绑定的新许可。已开始的一次流式 HTTP 响应不会中途被撤回。续签会重新核对当前发布、地区、媒体、授权期限和会员事实；同一有效播放会话内已开始当集的免费 N 豁免继续适用，刷新/新开/切集按当前规则重新判断。

字幕单独替换不改变视频 grant，后续字幕读取使用当前字幕绑定；客户端已经缓存的旧字幕不保证即时刷新。短期 grant 最长 120 秒，受会话/版权/权益到期更早时间约束；开始媒体字节读取后会话最长为该次视频时长的 2 倍加 300 秒。退款、下架、地区或授权改变按现有每次请求检查独立处理。未实现自动旧文件回收，不承诺旧许可永久可用，也未开放持久原始媒体地址。
