# 05 独立阶段验收证据

2026-10-07。最终应用源码 `72c1235bae361c8f5e5eb1a261163fe70d8abb5f`；共同起点 `501dc2584b8973239669e346fd12dcca19e6ce47`，正常合并契约 1.0.3 固定补丁 `6fe17f126b3e1d21e3b3a925973281f31d3945b6`。交付说明见 [05.md](../../handoffs/05.md)。

## 配置与结果

| 验证 | 证据和结论 |
|---|---|
| 默认装载全套 | [full-regression.txt](full-regression.txt)：246 项、2221 断言通过；`H5_LOCAL_MODULES= python3 scripts/dev.py test`。清空私有开发开关，不修改公共默认装载断言；Acquisition 测试在 testing 显式注册自身 Provider |
| 模块专项 | [module-tests.txt](module-tests.txt)：32 项、253 断言；专属 PostgreSQL 测试库；两连接竞争付款、HTTP 防止未声明请求、实际03结算/Outbox与权益隔离 |
| 默认与显式运行 | [runtime-default.json](runtime-default.json) / [runtime-enabled.json](runtime-enabled.json)：默认 Acquisition 未装载；本机 local 显式 acquisition 后 ready。两者归因 false、snapshot(null)=null、外发关闭；地区模拟关闭。01/02新增读取绑定均仍为明确未就绪实现 |
| 启动 | [runtime-status.json](runtime-status.json)：web、queue、scheduler、database、http 五项均 true；8205 / PG5605。初次启动后又应用第三项增量迁移 |
| 迁移 | [migrate.txt](migrate.txt)、[migrations.txt](migrations.txt)：050001/050002/050003 均 Ran；开发库实际增量升级，专属测试库经过建表/回滚测试；没有修改已有已接收迁移 |
| Schema | [schemas.txt](schemas.txt)：OpenAPI 1.0.3 和 57 个正反样例通过 |
| 实际 HTTP | [http.json](http.json)：健康200、后台访客302登录、未冻结/r入口503、实际后台Livewire无CSRF为419、虚构付款写接口404、默认未知地区目录403 |
| 浏览器 | [workbench.jpg](workbench.jpg)：独立本机员工登录和MFA；Meta/TikTok登记、保存重开、模拟状态持久化；TikTok停用/重开/恢复启用。片单与人员缺依赖提示已实际点击；本机未创建假片单/链接/付款 |
| 依赖审计 | [composer-audit.json](composer-audit.json)：当日对锁文件的网络审计，无已知 advisory/abandoned；锁文件未改，无新增Composer/npm依赖。SHA256：077219134203096b32abb3db2685c3defbab1c893ea89345f2882b2e7596ad48 |

工作区 `/Users/hejunxiang/.codex/worktrees/h5-05-acquisition/H5`；开发库 `h5_d2a32071a6`，测试库 `h5_d2a32071a6_test`，独立会话/缓存/队列/.env/密钥/素材目录。不复制他人的环境、数据库或素材。预览仅留两个人工命名的本地测试像素，没有实际投放账户资料。

## 修复与不成功尝试保留

- 早期重试测试曾把 JSON 复原前后的 PHP object/array 作严格身份比较，出现一项失败；见 [initial-failed-retry-test.txt](initial-failed-retry-test.txt)。另外两平台在同一用例重复配置 Http::fake 时，测试客户端旧通配响应被累积。按平台重置测试客户端、改为比较实际请求字节；[retry-regression.txt](retry-regression.txt) 9项/69断言通过，最终完整回归继续覆盖。未放松事件 ID、实际报文、金额或环境断言。
- 真实浏览器发现停用已入库但重开下拉仍显示启用：布尔 false 与 HTML option 的0/1不匹配。`3d3304f` 将表单展示值归一为0/1；[ui-state-regression.txt](ui-state-regression.txt) 4项/44断言通过。浏览器修复前实际 value=1，修复后 value=0且选中“停用”；随后恢复测试资产启用，再刷新验证。
- 初次 CSRF 探测误用了不存在的 `/livewire/update`，404不构成CSRF验证，保留 [http-initial-wrong-route.json](http-initial-wrong-route.json)。读取实际路由后改为 `/admin/livewire/update`，419复验通过。没有据此修改路由或关闭中间件。
- 首次依赖下载有一次TLS下载失败；使用原隔离目录继续安装成功，未复制他人vendor。一次未提升权限的迁移状态读取受到本机沙箱连接/日志写权限限制，改用本工作区授权执行复验通过。
- 收尾写日志曾报 `no space left on device`；只清理本任务新下载的Composer缓存约30MiB，没动数据库、业务数据、其他目录。余量恢复后五项启动、迁移、Schema、后续源码全量回归成功。没有迁移运行目录，也没有以环境故障掩盖功能失败。

## 不在本次证据范围

01/02新读取提供方的真实组合、真实片单选剧/多人名单操作、D05浏览器来源与访问统计、04隐私/脚本插槽、真实邮件、官方Stripe、Meta/TikTok真实HTTP接受及平台确认、广告花费/ROAS、对外部署和正式收费均未通过本次验收。完整站点验收仍交00/06。

本地HTTP替身中的 `api_accepted` 是响应分支测试，不是现实平台结果。各平台技术依据和限制见 [official-platform-notes.md](official-platform-notes.md)。
