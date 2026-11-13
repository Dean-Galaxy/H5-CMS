# 单次页面探针的独立 staging 准备

2026-11-12。正式应用基线 77d28d157ee40e91c493dec1a2f47b6dcf73e1c7，05 源码 6188cda8cfe07106e58abec1fe032588579da49f、交接 385d9fbeee0182287ff93ddb8c8c5164ddbdab01。00 已接 c904/6e56 并独立验证 30 项 421 断言。00已部署的独立staging为541711176e8834d7d9215dabf9d5f1b7960f8a06，包含01窄array准入及05页内隐私组件。本文实际部署状态来自00回执，当前修复仅更新源模板及回归。

## 最小拓扑

沿用现有主机、Nginx、PHP-FPM 和 PG 服务。专属系统用户 h5_page_test、目录 /srv/h5-page-test、一个 ondemand pool/单 worker 和 PG 库/角色 h5_page_test。无新增云资源、域名购买或常驻 queue/scheduler。production 77d 的 /srv/h5/current、pool、库、配置和任务不作为此测试的可写目标。

00 已只读确认可复用 staging.sparklingshort.com 和现有 /etc/letsencrypt/live/staging.sparklingshort.com 证书。主域 production vhost 只有正式主域，另核其他 default vhost 无 staging 名称冲突即可。本模板域名/证书已固定。旧私密8443仍指正式 current 和 h5-staging.sock，保留原状。公网443测试站使用新目录与 h5-page-test.sock。当前正式PG为127.0.0.1:5432、db/user h5_staging，目标配置明确为 h5_page_test，禁止混用。当前IPv4/32 SSH准入仅用于工程维护，后续用户浏览器须直连测试HTTPS，探针拒绝回环/私网IP。

## 数据与身份

1. 空库按最终候选迁移。新 2026_11_12_050010_create_acquisition_page_tests 只在该库新增诊断表。以 route:list、migrate:status 和源码清单核实际版本，不估计总迁移数。库角色为非超级用户、不能建库/建角色，无生产表授权。须核 pg_hba 规则，限定该角色只连自身库并拒绝其余库及本地 peer 通路。已有生产 PUBLIC CONNECT 不能当隔离已成立。
2. 原 Owner 登录和验证器复用仅做一次受控单行快照。identity_staff 内 role 是单一字段，没有独立角色关联表。必需字段为 id/name/email/password 哈希/role=owner/active，目标 version、session_version 独立初始化。保留原身份 UUID 用于目标库关系。源端按明确 UUID 且 role/active/MFA 已核的唯一一行只读导出，不扫或复制全部员工、访客、账务或广告历史。
3. app_authentication_secret 由源应用原密钥在内存解密，经私密管道进入目标应用，使用目标新 APP_KEY 重新加密。仅目标写新行。不能直接复制密文、APP_KEY、remember_token、recovery_codes、sessions、密码重设 token、缓存、MFA 已通过状态或重放记录。77d 的 MFA 秘密为 Staff 的加密字段，role 同在该行。sessions/cache/审计表由空库迁移建立，不导入旧记录，也没有需要搬迁的独立 MFA 业务表。目标 recovery_codes 留空，新会话必须用原密码和验证器正常登录。测试站密码变更只影响目标快照，禁止以同步脚本回写源站。
4. 相同 TOTP 种子意味着跨站重放检测状态独立。后续操作将两个站视为独立会话，部署联验用不同时间步。需要跨站共享单次消费时属于身份模块增量，不能凭复制会话绕过。测试结束停入口后删除该单行 MFA 副本须按保留/备份方案处理。
5. 本轮资产、版本、广告 grant 和短期意图均在新库创建。正式 Acquisition 表为 0，不复制测试授权或伪造同意。平台资料未齐可先完成空资产页面及拒绝路径。Meta test 资产/私密 map 只在后续窗口绑定，不能把正式资产改标 test。

## 配置、邮件停点与缓存

runtime.env.example 是目标配置，其中新密钥和新库口令由工程生成至 0600 文件。当前正式站用 Resend SMTP，其凭据不复制。01 已由 00 派发窄修，只有 staging+controlled+acquisition_staging_test 才允许精确内建 array 邮件叶子，production 和嵌套 failover 继续拒绝。该依赖已在独立组合接收，77d单独源码仍须先组合此准入再启动测试配置。

Acquisition 配置来自 app/Modules/Acquisition/config.php，正式 ready=false 时该 namespace 未加载，不能复制正式缓存的 null 值来作配置。目标 .env 只指向 /srv/h5-page-test/shared/app.env，由工程安装为0600。CLI从目标 h5-platform 目录加载此文件，不用shell直接source Dotenv。新目录必须实际设置 H5_ACQUISITION_STAGING_TEST=true，加载正确 provider、ready=true 及 acquisition 配置。transport=disabled、external_enabled=false、platform_reviewed=false、credentials_file 空，C/R1/analytics 关闭。此为可显示/准备的受控入口，外发还需真实资产和另行窗口。

目标独立 storage、bootstrap/cache、mail/media、日志及缓存 session。新配置缓存固定 /srv/h5-page-test/shared/bootstrap/config.php，由 CLI 和 FPM 同用 APP_CONFIG_CACHE。从最终 release 生成，禁止复制源站 config.php、services.php、routes 缓存或绝对路径映射。核缓存 app.env、app.url、base_path/storage_path、DB 角色/库、session cookie、Acquisition 六开关及凭据路径，不能只看 .env。读取缓存会含秘密，公共回执只列安全布尔与摘要。

禁邮件由 array 叶子和 viewer_email_pages_enabled=false、边缘写入口默认拒绝实现。支付 driver/new_purchase_provider 均 unconfigured、两 PayPal 开关 false、无支付密钥。QUEUE_CONNECTION=database 仅把意外排队留在本库，没有 worker 消费。原 cron/timer 不指向此目录，不启动 dispatch、billing、content 或通用任务。原 production 调度照其现行范围运行。

## 原生接入顺序

批准实施窗口后由 00 完成以下顺序，步骤中的配置缺项先停，不向主站借写配置。

1. 核域名与证书、安装路径及文件权限。建立专用用户与目录、角色/库的 SCRAM 限定，生成独立 APP_KEY 和密码，校验对生产库无权限。创建 shared 的 storage/logs/framework、media、bootstrap 和私密配置目录。
2. 固定最终 release，复用同锁依赖与已构建静态文件，清除复制来源的 .env/cache/runtime。按目标环境执行 migrate --force，核 050010 和目标 UTC。之后仅导入已审的一个 Owner，清目标 remember/recovery/session，验证新密钥可解密其 MFA。
3. 在新目录显式指定 APP_ENV=staging、APP_CONFIG_CACHE 生成 config:cache。使用 check_runtime.php --expect-env=staging --require-cache --require-db-utc，核正确工作区/缓存/PG 后才准备路由。
4. 读取目标登录HTML中script的data-update-uri，核其同源、精确路径及POST方法，并保留目标哈希路由兼容，再渲染两份模板。页面实际声明/admin/livewire/update，不能按route:list猜测唯一哈希路径。用现主机 php-fpm -t、nginx -t 检查完整配置。仅后续批准时 reload 已有服务使新 pool/vhost 生效，所有旧站配置须逐项比对和可回退。
5. 干净浏览器用原 Owner 密码和原 MFA 打开 /admin/acquisition-page-test。无 Basic 小窗，未登录跳原 /admin/login，未完成 MFA 拒绝，跨站/缺 CSRF 拒绝。静态、登录Livewire和同源cookie均来自staging Host。实际填写不存在的合成账号并点击登录，应由应用返回页内凭据错误。缺CSRF的最小POST应419，这一步仅证路由和CSRF，真实Owner/MFA成功仍须本人。
6. 同一浏览器直接使用探针页内复用的原隐私组件。只允许 GET /api/v1/tracking/context 和 POST /api/v1/tracking/consent，先显式广告同意、分析关闭，再准备一次意图。无测试资产/未勾选/撤回/缺同意仍阻断。仅页面或 HTTP 200 不记真实授权/发送通过。
7. 本阶段结束保持 transport disabled。后续如获准发一次，由工程在同一 release/cache/env 执行 acquisition:page-test 精确UUID，10分钟内最多一次；不扫队列，不自动重试。HTTP 接受与 Meta 面板确认各留回执。

## 路由最小表

| 入口 | 允许 |
| --- | --- |
| /admin、/admin/login、/admin/acquisition-desk、/admin/acquisition-page-test | GET |
| /admin/logout | POST |
| /admin/multi-factor-authentication/set-up、challenge | GET，最终以 route:list 核实际 MFA 路径 |
| /admin/acquisition-page-test、其 /withdraw | POST，同源 CSRF |
| /admin/livewire/update（HTML实际声明）及原哈希Livewire /update | 精确POST，原web/CSRF和持久staff/MFA校验 |
| 哈希 livewire.js/livewire.min.js、admin-ui、audience、build/css/js/fonts | 仅实际存在的 GET 资源，无 PHP 文件静态旁路 |
| /membership、/api/v1/auth/csrf、/api/v1/tracking/context | GET |
| /api/v1/tracking/consent | POST，同源 CSRF |
| 其余业务/API/注册/支付/回调/采集/events | 默认 503 |

541探针页内复用原tracking隐私组件与analytics.css/js。空库关闭支付时/membership曾返回503，当前隐私流程以探针页内组件为准，表内GET仅保留模板兼容。模板没有开放通用 /api/v1/admin 或所有 /admin 页面。Livewire 内部动作仍须原权限与 MFA，边缘无法按组件动作细分，因此目标 Owner 的其他动作只会落独立库。部署时须核 UI 所需资源与精确 MFA 入口，不能为消除 404 扩成整站放行。

## 到期清理与停止

意图10分钟到期后不可发送，但物理清理由原命令执行。完成、阻断及撤回立即按模块清私密字段，崩溃 attempted 不重领。工程在窗口结束及过期后，显式运行同目录 acquisition:analytics-prune --limit=1000 并检查过期 context_encrypted/privacy/pixel 引用已清。一天后再次按原命令清诊断元数据，记录实际剩余数和有界批次，未执行时标待清理。可以在已有运维维护时执行，不新建长期任务。transport disabled 后才做结束核验。

暂停先禁此测试 vhost/取消后续 CLI、清短期意图并备份新库私密记录，确认无在途发送再撤本 pool，按实际生命周期停专用入口。原服务进程管理由 00 统筹。回滚仅恢复该 vhost/pool/目标发布指针和目标配置，保留已消费事实，不回灌生产或重置 attempted 以便重发。

## 00 仍需只读确认的字段

已确认 production77d、SMTP Resend、Acquisition全空与未加载、正式PG地址和身份、单pool h5/h5且ondemand max_children=1、现有sites-enabled/私密8443与两域证书路径，以上不重问。实施前剩余仅以下六组。

- 只需核其余 default vhost 没有 staging 名称冲突，证书文件对 Nginx 可读；域名及既有证书路径已由00确认。
- 新库/角色是否已存在，pg_hba 限定新角色的规则，以及目标连接实际 UTC。正式端口/库/角色已确认，无需再次提供。
- 原 Owner 明确 UUID、role/active/MFA 已配置布尔和必要列类型。仅源工程内读取秘密并私密转换，回执不回显邮箱、密码哈希、MFA 或恢复码。
- 现 FPM pool 父服务的 sandbox、可读 /srv 路径、Nginx 用户和 socket 权限、新 UID 可用性与单 worker 内存余量。
- 固定目标SHA与其登录HTML实际data-update-uri，再核route-list和MFA路径。Git archive缺发布静态时，应补同composer.lock的已发布Filament css/js/fonts，按清单逐摘要验证；此前00补38文件。
- 批准后测试窗口负责人、结束及一天后清理执行入口。平台 Token/测试码暂不需要收集。

11/12首次准备交付仅做源码、配置与模板校核。此后00已完成独立staging启动、TLS、公网登录页及MFA单行迁移；本人有效账号/MFA成功及Meta接收仍待反馈，实际状态见00最新交接。

## 11/12原准备阶段验证（历史）

对正式77d和05候选逐文件核对，静态材料没有应用、公共Guard或主站配置改动。Nginx 1.28.3 本机编译件未含SSL模块，首个完整模板检查在该限制拒绝。随后只在临时配置去掉TLS指令，原生核其余map/location/FastCGI语法。TLS证书与目标机完整配置、FPM语法、真实Owner迁移、浏览器和外发均留待实际窗口，未启动服务。静态检查包含邮件停点声明、支付与采集关闭项、独立DB/缓存/会话/路径和精确路由占位符。


## 2026-11-13 精确登录提交修复

模板新增且只新增POST /admin/livewire/update规则，原哈希规则保留。00现场回执记录缺CSRF从503变为419，干净Chrome真正提交不存在的合成账号后得到200及页内“登录信息有误”，无503覆盖层。此为负向登录和路由验收，真实Owner/MFA登录仍待本人。

只读入口预检从实际登录HTML解析script[data-update-uri]，限定同站HTTPS与精确Livewire提交路径，禁止重定向，仅发送一次不含CSRF的{}，预期419。只输出路径/status，Cookie仅在内存且不输出，不记录HTML或真实账户。此工具本轮仅做静态及合成HTML解析检查，没有运行公网预检。00已有独立现场实证，无需重复47组。

此前完整Laravel/FastCGI夹具缺数据库，CSRF拒绝审计引起500，该夹具已撤出交付，不计为当前候选通过。其历史回执保留在06私密.runtime/page-test-login-20261113。00现场证据保存在主仓私密目录 .runtime/00-staging-login-20261113/，包括route-verification.json、login-submit-verification.json、submit-diagnostic.json及截图。真实503→419与Chrome实际合成账号提交200分别记，Owner/MFA真人通过仍须本人确认。

在另行需要复核时，明确执行以下命令。默认不运行网络请求。

```sh
python3 scripts/ops/tests/check_page_test_login_route.py --login-url https://staging.sparklingshort.com/admin/login
```

部署只更新staging既有map此一规则，00负责nginx -t/reload及前后摘要。回退仅撤该规则并核语法，旧提交会重新被503拒绝，需明确向使用者说明。正式配置、付款/邮件/广告不在此修复范围。
