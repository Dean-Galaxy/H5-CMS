# 02 PromotableCatalog 1.0.3 验证记录

2026-10-07。本次基于固定公共标签 h5-shared-v1.0.3 = 6fe17f126b3e1d21e3b3a925973281f31d3945b6，实现供 05 使用的美国可投放片单。02 在已有独立分支 codex/02-content 正常合并该固定提交，实际为快进，无重置或冲突。初始 h5-foundation-v1 = 7a7ee4b57b0edcf747ba35111c68fd08db186b20 仍是当前提交祖先；未移动标签。

## 实现和调用边界

只变更 ContentServiceProvider、新增 ContentPromotableCatalog 及本模块 PromotableCatalogTest；其余是 02 证据和交接文档。没有新增公开 HTTP 路径、迁移、配置变量或依赖。公共类型完全使用 00 已冻结的 PromotableCatalog、PromotableDrama 和 PromotableDramaPage。

每次读取都调用真实 IdentityContext.requireStaff(PublishedCatalogRead)，核验员工、后台 MFA 和权限。Owner、ContentOperator、Acquisition 可读，Support、观众和匿名不可读。读取不调用会员接口，不授予播放。美国库存直接按 US 授权筛选，不读取或修改观众地区解析器。

入选条件为当前 published 剧目、US 地区、授权区间 [license_from, license_until)、至少一集 published_at 不晚于当前时间且私有媒体可用。复用 MediaStore.playable，核对 ready、非空 MP4、完整 HLS 清单及引用分片；绑定字幕时还核对 ready 和非空 VTT。每次读取清除文件状态缓存并重新检查。不可访问目录、非法内部路径或无可用公开媒体均不作为候选。

published_episode_count 统计当前实际公开记录，不使用 total_episodes。某个已公开集的媒体后来损坏不会抹除其发布事实；剧目须另有至少一集媒体可用才仍入选。输出仅有 id、title、locale、country_code、published_episode_count，语言沿用已有英文/中文字段回退。

排序为 sort_order 升序、ID 升序。分页以最后返回项为位置，每批扫描 50 条直到找到 limit+1 个可用项，避免无效媒体占满一批导致漏页。limit 为 1–50；允许翻页时调整 limit。HMAC 游标绑定用途、US、locale、固定排序和 catalog_version；签名、类型、条件或版本不符统一按现有 Content 方式 422。跨公开目录或跨语言游标不能复用。

单剧读取不依赖当前页，供 05 回填选择及保存前复核；格式错误 422，不存在、下架、过期、非美国或媒体不可用统一 404。结果只代表读取当时的库存条件；05 保存时需复核单剧，之后观众落地及申请播放仍执行原有 CatalogReader/PlaybackAuthorizer。

## 已运行检查

| 检查 | 结果 | 证据 |
|---|---|---|
| 新增专项 | 9 项、237 断言通过，1.94 秒 | [target-tests.txt](target-tests.txt) |
| 当前 01–04 组合全量 | 223 项、2205 断言通过，35.06 秒；包括原定时并发、越权与受控媒体回归 | [tests.txt](tests.txt) |
| 公共契约 | OpenAPI 1.0.3 与 57 个正反样例通过 | [contracts.txt](contracts.txt) |
| 正常运行的真实库存 | 真实剧目返回 6 集公开；zh_CN 回退英文；ZZ 前后不变，公开目录仍 403；没有新增播放许可或会话 | [runtime-check.json](runtime-check.json) |
| 既有真实 HTTP 媒体流程 | 22 项通过；6 集公开、免费第五集、匿名第六集拒绝、CSRF、签名、跨会话、分片 Range、字幕 | [http.json](http.json) |
| 独立环境启动与迁移 | 网站、队列、调度、数据库、HTTP 均正常；已采用迁移全部 Ran | [runtime-start.txt](runtime-start.txt)、[migration-status.txt](migration-status.txt)、[runtime-status.json](runtime-status.json) |
| PHP 格式 | 本次 3 个 PHP 文件通过 | [format.txt](format.txt) |
| 锁定依赖审计 | advisories/abandoned 均为空 | [dependency-audit.json](dependency-audit.json) |
| 已接收浏览器依赖 | 安装公共锁定 hls.js 1.7.3 并校验摘要；不是本次新增依赖 | [browser-assets.json](browser-assets.json) |

新增测试覆盖每种员工角色和 MFA、观众/匿名、真实发布记录、上传不等于公开、未来公开时间、US 和到期边界、媒体/字幕丢失与空文件、无效路径、语言回退、超过一批无效媒体后的稳定分页、页外单剧回填、游标篡改/跨条件/版本失效及最小字段防泄露。

实际运行使用本工作区默认 Provider 注册，不替换会员或内容服务。CLI 验收助手使用已存在的负责人记录，并在内存绑定员工会话及 MFA 标记；这次 CLI 检查没有重新执行浏览器密码/TOTP 登录。自动测试使用隔离测试库、真实身份权限服务和明确的 testing 媒体文件；它们不是实际视频。真实 HTTP 检查另读已有处理素材。

## 环境和数据

工作区 /Users/hejunxiang/.codex/worktrees/h5-02-content/H5；开发库 h5_2e3d6828b8，测试库 h5_2e3d6828b8_test；独立 PostgreSQL 5602，HTTP 8202。没有复制其他工作区的环境或连接其他工作区数据库。

本次启动正常应用公共基线中已接收的 Audience/Billing 迁移到 02 开发库，本次实现自身无迁移。实际私有媒体仍为 /Volumes/KESU/H5-02-runtime-2e3d6828b8/media；dev.py status 的 media 字段是初始化目录提示，并非当前 H5_MEDIA_ROOT 的实际值。

真实测试剧 A Star Between Us 的 62 集视频满足现有文件可播放检查，62 份 ready 字幕 VTT 非空；已公开 6 集，计划暂停。未修改原素材、版权登记、公开范围或排期。本次没有重复做全剧解码和人工视听；文件可用与字段登记不代表版权、字幕质量或真机播放验收。

一次检查启动在 shell 创建临时文件时报告空间不足，当时尚未执行检查。仅清理本工作区可重建的 Composer 下载缓存和已安装浏览器包的下载缓存，共 39,326,242 字节；源码、已安装依赖、数据库、凭据、媒体均保留。随后全部检查完成，无持续失败项。

## 00 复验与 05 使用

在复验工作区按公共接入说明建立自己的环境，使用该工作区根脚本运行；不要复用 02 的配置或数据库。

```sh
H5_LOCAL_MODULES= python3 scripts/dev.py test
.runtime/contracts/bin/python scripts/validate_contracts.py
python3 scripts/dev.py start
python3 scripts/dev.py status
```

契约验证 Python 环境按 docs/01-开发接入说明.md 配置。真实 HTTP 检查可使用 Content 自带 Tools/live_smoke.py，但须传复验库中真正已发布、期限有效的剧目和分类 ID；不能照抄 02 的数据库对象并声称真实导入。

05 在已认证的后台请求上下文中解析 PromotableCatalog，分别调用 promotable 获取分页、promotableDrama 回填并复核所选 ID。异常由既有 API 错误机制处理。无需绕过 MFA，也无需解析内容表或返回媒体字段。本次未验证 05 页面，待 00 串行接收后由 05 正式消费。

02 内容后台仍为 http://127.0.0.1:8202/admin/content-library。此次没有新增供产品经理手动操作的片单页面。D03、D05、外部测试站、真实邮件、官方支付、广告发送和真机验收保持原有分工与门禁，本次不作放行。
