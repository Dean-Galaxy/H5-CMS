# 02 媒体处理命令退出语义修复

- 基线：`64702a1c6414d963509315c69081eff4837ac15b`。原 02 工作区 `/Users/hejunxiang/.codex/worktrees/h5-02-content/H5`、分支 `codex/02-content`，原 HEAD `3e10731ba68b13d8e3b49d0556515766529389d7` 干净，已正常快进至基线，未重置。
- 交付提交：本文件所在候选提交；完整 SHA 随回执提供。公共契约沿用基线，无定义变化。
- 影响与关闭条件：此前处理结果 failed 仍返回 0，调用方不能据此发现失败。现在空队列和 ready 返回 SUCCESS(0)；首次非 ready 保留原 ID、状态及安全错误码输出，立即 FAILURE(1)，不领取 limit 剩余项；非法 limit 仍 INVALID(2)，异常继续由框架处理。

## 文件与验证

仅修改 `h5-platform/app/Modules/Content/Console/ProcessMedia.php` 的三行判断，新增 `h5-platform/tests/Feature/Content/ProcessMediaCommandTest.php` 及本交付记录。MediaStore、领取条件、租约、attempt、配置、迁移、发布及账务均无变更。

2026-11-02 仅运行新增专项：**7 passed / 42 assertions / 0.64s**。覆盖空队列、真实合成 SRT→ready、真实无效字幕→failed/INVALID_SUBTITLE、limit=2 失败不再领取、模拟竞争返回 processing 也停止、5 种非法 limit、框架异常非零。竞争状态和异常通过仅测试模型监听器注入，结束恢复监听器；普通失败使用真实 MediaStore。两个变更 PHP 文件格式检查通过（测试文件仅调整 import 顺序）。

运行绑定原 02 专属 `h5_2e3d6828b8_test`、本机 5602，自有随机临时媒体目录及合成数据；未使用真人资料。依照 `scripts/dev.py` 的环境与数据库隔离规则，专项调用为 `artisan test tests/Feature/Content/ProcessMediaCommandTest.php --compact`。仅启动本工作区 PG，结束正常停回原状态，未启动 Web/worker/scheduler。原始专项回执保留 `.runtime/logs/process-media-command-fixed.txt`，不入 Git。

候选未部署、未 SSH、未处理云端或旧素材。无新依赖、迁移或公共接口需求；00 独立接收后统一留存与决定部署。
