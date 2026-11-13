# 02 内容限定迁入候选（离线准备）

目标：在独立投流 staging 恢复同一批 3 部/45 集内容管理、已发布免费观看与选剧，保留原 Owner/MFA、Acq 像素/Token。仅运维数据复制，无应用改动、SSH、真实数据或部署。02 基线 `536bc7d6ef107c6a53fa4ed55bf19e48c1461df3`；按目标应用 `a5f7691a680b71e0a03aedfa35b18a67d1b0628d` 的四个 Content 迁移及模型核对，Content 表结构与该基线一致，当前 Content 模型无加密 cast。无需正式 APP_KEY 或内容解密重加密。候选 SHA 随交付回执提供。

## 精确范围

白名单按插入顺序：`content_settings`、`content_assets`、`content_categories`、`content_dramas`、`content_drama_categories`、`content_episodes`、`content_plans`、`content_publications`、`content_banners`。只选显式列出的 drama ID；分类只取关联项、asset 只取剧封面/分集视频字幕/Banner 引用项。未绑定候选不导入，不处理 pending；引用素材非 ready 则停止。发布历史保留，防重键/所有 ID/version/access_version/时间/授权字段/状态原样，不篡改已有自制全球授权、不增加版权审批流程，不执行排期。

FK：assets/categories→dramas→关联/episodes/plans→publications/banners。created_by 不带员工记录：必须目标已经存在同 ID、active 的 Owner（只查存在性）；缺失拒绝，不复制任何密码/MFA。**不迁** play_sessions/grants/operations（许可/观看身份/含员工名历史非编辑发布必需），其他 Identity、Billing、Acquisition、session、cache、jobs、audit 表完全无写入语句。00 负责源端只读快照与目标 PGdump/配置备份及保护表前后核验。

settings 不是假定空表：源必须 singleton id1/default_free_count5。目标相同则不写；只有目标九表除初始 settings 外全空，且 settings 严格为 id1/free5/version1/catalog_version1，才在明确 apply 中初始化为源版本。其他任何差异均拒绝。现有目标同主键逐字段完全相同才跳过；版本或任何字段不同一律拒绝，不用 upsert/覆盖/删除。

每个绑定 asset 的 source_key 文件及当前 output_key 整棵目录逐 SHA-256（包括双档、清单、分片及目录内现有产物），保留相对键。拒绝路径穿越、symlink、hardlink、空文件、source SHA 不符、非独立根。源目标不重叠；只以普通字节复制创建新 0600 文件/0700 目录，明确目标 UID/GID 并校验，不共享生产可写文件。已有文件摘要或属主不同则拒绝，不覆盖。失败已复制文件保留诊断，无 DB 引用；不自动清理、重试或退款式删除。

00 已给历史只读数字：96 个 source 共 3,896,729,275 bytes，完整正式 media 1705 文件/12,038,939,386 bytes，可用 22,490,726,400 bytes。**本候选没有扫描真实文件**；实际绑定 asset 数、文件数和精确总量由 export manifest 输出，copy 另报仅缺失文件字节及磁盘前置检查。运行前 00 复核空间余量；不额外在服务器生成约 12GB 全媒体密文，不称新增全媒体离机备份。原正式媒体与独立副本保留，PGdump+配置+manifest 按 00 方案离机。

## 工具操作（仅 00 执行，以下均占位）

工具：`h5-platform/tests/Feature/Content/Support/transfer/content_transfer.py`。Python≥3.11、psql；无第三方依赖，不启动 Laravel。00 在执行机器私密 libpq service 中配置源端只读连接、目标独立写连接；口令不放命令行/仓库/报告。服务名只允许字母数字/下划线/短横线；要求显式数据库名称并核 current_database，目标不能与源同库名。固定工具应用参考不等于运行版证明，00 先核实际应用/schema 和目标服务确为 h5_page_test，绝不把正式库配置为 target。

```sh
python3 content_transfer.py export --source-service SOURCE_READONLY --source-database h5_staging \
  --source-media /PRIVATE/source-media --drama DRAMA_ID_1 --drama DRAMA_ID_2 --drama DRAMA_ID_3 \
  --package /PRIVATE/content-scope.json
python3 content_transfer.py copy --package /PRIVATE/content-scope.json \
  --source-media /PRIVATE/source-media --target-media /PRIVATE/target-media \
  --target-service TARGET_ONLY --target-database h5_page_test --target-uid TARGET_UID --target-gid TARGET_GID \
  --plan /PRIVATE/reviewed-plan.json
# 核对精确3剧/45集、scope摘要、settings、文件总量与空间、Owner、目标隔离与备份后：
python3 content_transfer.py copy --package /PRIVATE/content-scope.json \
  --source-media /PRIVATE/source-media --target-media /PRIVATE/target-media \
  --target-service TARGET_ONLY --target-database h5_page_test --target-uid TARGET_UID --target-gid TARGET_GID \
  --plan /PRIVATE/reviewed-plan.json --apply --confirm-digest PACKAGE_SHA256
```

export 是只读 REPEATABLE READ 快照，媒体摘要后再核源选定行未变；package/plan 为敏感运营快照，0600 新建、不覆盖既有文件，不入 Git。默认 copy 为 dry-run，仅读取/校验并新建私密 plan，DB/媒体零写入。apply 必须与已审 plan、target service/root/UID/GID、package 摘要及目标九表完整状态一致。复制并核全部目标文件后才开启 DB 事务；锁九表、再核目标行未变、Owner 存在，写白名单并在 COMMIT 前核九表预期完整行集合。冲突事务回滚；DB 异常不打印服务器原文。重复执行先新建不同 plan；同数据同文件零新增，不覆盖之后用户编辑。

00 执行期间短暂保护源/目标内容编辑及媒体写入，确保扫描/复制不竞态；不需全站停机，不关闭原像素页，不清库。目标此前未处理的队列/排期必须保持关闭，此脚本不启服务/发事件/发信/收费。恢复持续运营入口、worker及计划运行由 00/06 另接，不由该工具承包。

## 必要验证与边界

专项合成测试 8 项通过（7 纯离线＋1真实 PostgreSQL 端到端），真实 PG 用原02实例5602上两个**新独立** `h5_02_transfer_{source,target}_test`，不使用应用库/真人资料；结束 PG 恢复停止。属主参数及库名绑定补齐后，仅受影响PG集成在新独立合成库复验，不复跑旧全套；最终PG专项1项通过，含库名/属主及内容SQL字面量安全编码；历次仅对应增量复验，原失败/回执均保留。端到端验证 export 只选绑定 asset、dry-run 零内容/媒体写入、实际 SQL/FK/文件复制、settings 初始化、重复执行不新增、版本漂移拒绝、目标合成像素/身份保持与源候选保持。真实 PG 夹具为最小合成列结构，不冒称真实3剧迁入或前台播放通过；00 需用实际 schema/快照独立核接。

```sh
python3 -m unittest discover -s h5-platform/tests/Feature/Content/Support/transfer -p 'test_*.py' -v
```

默认跳过 PG 集成；仅对两个新空 `_test` 库显式设置 `CONTENT_TRANSFER_SOURCE_TEST` / `CONTENT_TRANSFER_TARGET_TEST` 为私密 service 名后运行。集成拒绝非空库，不删除任何库；原始回执留02私密 `.runtime/logs/content-transfer-tests.txt`；最终受影响PG回执 `.runtime/logs/content-transfer-final-tests.txt`。不重跑全产品/人工/上传优化。执行后验收交00：3剧45集与ID/version/已公开集数、所选文件逐SHA/属主、原Owner/MFA与像素配置保持；后台同账号编辑/预览、前台合法免费集和推广链接选剧按实际入口必要小轮核验。

## 真实问题与防再犯（持续补充，关联00统一索引）

按本人2026-11-13在07的要求：后续每次真实问题在现有交接补充触发环境、已确认根因/未知项、修复提交、实际验证及可执行预防；保留失败，不存凭据/隐私，不另造重复编号。项目结束待本人明确通知再统一提炼 skill，现在不创建或安装。公共编号与索引由00维护。

### 本次迁入：UUID Owner谓词类型不匹配

- 现象/环境：00在真实库完成只读export（3剧45集/93绑定ready资产）后，目标dry-run报PSQL拒绝；目标数据/媒体未写入。00以无个人数据readonly探针确认 `operator does not exist uuid=text`。原export和1674文件摘要保留，不因修补重新导出/重哈希。
- 根因：安全hex literal返回text，工具两处Owner谓词使用 `identity_staff.id = literal(...)`，真实id为uuid；初版PG最小夹具把id/created_by简化成text，掩盖类型差异。此前合成通过不代表真实schema通过。
- 修复：`bea306d4f9a0cd190b37f381d09d61a34c8c5064` 两处改为 `id::text` 比较，保留hex编码、active Owner门禁和精确scope；夹具identity_staff.id及content_assets/content_dramas.created_by改uuid并使用合法合成UUID。
- 实际验证：仅受影响PG端到端1项1.355秒通过，覆盖UUID下dry-run/apply、幂等、目标版本漂移拒绝、inactive Owner拒绝且未生成plan/媒体、保护像素/身份保持；自有新合成测试库5602，结束恢复PG停止。回执 `.runtime/logs/content-transfer-uuid-tests.txt`。实际云迁入接收结果由00续记，本条不冒称已完成真实迁入。
- 防再犯：凡跨库工具将SQL文本表达式与真实类型列比较，必须核对实际schema并显式转换；最小夹具可省无关列，不能简化谓词/FK/主键的关键类型。先dry-run确认零写入，失败修补后复用已冻结源包，仅复验受影响路径。

### 待00排查：staging内容管理四tab正文空白

本人07实报只有导航壳、无业务正文；根因未知，00统一排查且独占云写，02尚未收到定向修复。保留该阻断，不以HTTP200/导航壳当完整功能通过。后续关闭需真实权限下看到必要业务正文并完成受影响操作；具体修复提交/验证证据待00接续，02不重复部署或重跑。
