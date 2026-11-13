# 持续集成与运营测试站

2026-11-13。00指定已部署起点 `a5f7691a680b71e0a03aedfa35b18a67d1b0628d`。本候选让正常页面进入应用既有权限校验，并提供独立的媒体处理与发布任务。这里只交源码与执行步骤；本轮未连接云主机、部署、开启任务或外发。验收证据及实际候选版本见 [06交接](../../docs/handoffs/06.md)。

测试站为 https://staging.sparklingshort.com ，后台 https://staging.sparklingshort.com/admin 。用户直接用现有员工账号、密码和验证器登录。00最新回执已确认本人登录及测试资产私密录入；不再复制、重置或索取这些资料。验收后正常菜单、内容和任务保持可用，不按原单次探针窗口自动关站。

## 保持的隔离与关闭项

复用现有主机、证书、Nginx、FPM和PG服务，不新购资源。独立系统用户/PG角色/库为 `h5_page_test`，目录 `/srv/h5-page-test`、socket `/run/php/h5-page-test.sock`。生产仍按00当前77d版本和自己的配置、UID/库/任务运行，本候选没有其可写目标。

保留目标现有APP_KEY、Owner密码/MFA、测试像素、私密Token文件、cookie/cache及全部新增记录。`runtime.env.example` 是参数参考，不能整文件覆盖现有env；`database.sql.example` 是首次建库历史材料，现站禁止再执行。不得复制生产密钥、普通用户、会话、恢复码、订单或账务。

当前邮件array、注册/验邮页面关闭、支付driver与new_purchase_provider为unconfigured、两PayPal关闭，广告transport disabled/external false/platform_reviewed false，analytics/C/R1关闭。Meta资料保留并不意味着已发送。只安装本文两种内容任务，无通用queue、billing、dispatch或广告消费者。未来05单次平台验证由00单独编排，内容任务不会仅因其短时广告配置变化自动停用；内容unit网络仍仅准入127.0.0.1。

## 正常入口与边界

Nginx不再用探针路径白名单覆盖普通菜单。下列请求进入实际应用的Auth、角色、MFA、会话、CSRF、地区与媒体授权，不代表它们已经业务验收通过。

| 用途 | 入口 |
| --- | --- |
| 内容/上传/计划 | `/admin/content-library`，`/api/v1/admin/content/...`，`/admin/content/episodes/{id}/preview` |
| 员工/个人资料/内容设置 | `/admin/staff-accounts`、`/admin/profile`、`/admin/content-settings`及既有员工API |
| 本地订单/投流 | `/admin/billing-desk`、`/admin/acquisition-desk`、`/admin/acquisition-page-test` |
| 观众 | `/`、`/catalog`、`/dramas/{id}`及分集观看/授权、受签名保护的清单和分片 |
| 来源/隐私 | `/r/{code}`、tracking context/landing/consent/events |
| 后台交互 | HTML实际声明的 `/admin/livewire/update`，保留哈希Livewire兼容入口 |

所有`/webhooks`、会员checkout/cancel/resume、billing return/recheck、billing API checkout/subscription cancel/resume仍边缘503。普通会员页及观众登录等页面虽可到达应用，可能因当前支付/邮件关闭返回依赖不可用；须记录首个应用失败点交00分派，不能关Guard、伪造地区或启用真实付款消除错误。注册关闭仍由原应用执行。新业务路径随00接收的实际候选核验。

保持正确Host/SNI、TLS1.2/1.3、无Basic小窗、dotfile/PHP直接访问拒绝、私有媒体不作静态目录、请求头白名单。上传总文件走原1MiB分块协议，Nginx及现有pool上限2MiB；Upload-Offset、Range、If-Range及缺省语义保留。FPM模板未改。

## 00串行部署顺序

1. 固定接收后的完整SHA，记录staging当前指针/env/cache/Nginx摘要及各专属timer状态。备份新库、媒体和配置至既有加密离机链；可读dump或摘要核验不等于独立恢复。现有备份/恢复操作见 [恢复手册](../../docs/06-部署与恢复手册.md#备份独立恢复和不覆盖新付款的回退)。本候选没有迁移、公共依赖或业务实现变更。
2. 如接02内容迁移，在**仅测试站**停止本文两个timer并等待在途任务结束。必须中止时再停本service，保留RUNNING，不自动清标记/重试未知素材。02只迁内容表及被引用媒体；不导入身份、消费者、账务或生产秘密。核实际文件逐摘要、所有关系/发布状态及剩余磁盘后再恢复任务。00当前源端计1705文件/12,038,939,386字节，可用22,490,726,400字节；这是只读容量回执，不代表已经复制或恢复。
3. 在独立release安装同锁依赖和静态产物（沿用已核38件Filament公开文件），共享env/storage/media保持现有绑定。还须备齐原浏览器锁指定的hls.min.js及LICENSE；源码归档不含这些生成产物。仅复用00已核公开文件/本地官方包，独立复制不共享inode、不改正式、不把vendor塞Git；从目标release执行 `python3 scripts/install_browser_assets.py --check`（不联网），并配置精确 `/vendor/hls.js/1.7.3/hls.min.js` 静态入口，两项缺一均不能交浏览器。重建配置缓存时只用staging环境及 `/srv/h5-page-test/shared/bootstrap/config.php`，不复制生产缓存。比较缓存绝对路径的键和值，除目标版本路径及明确变更项外保持。以实际 `route:list` 核菜单/API与登录HTML的data-update-uri，不能猜唯一哈希路径。
4. GeoIP由00把已存在的DB-IP库复制到 `/srv/h5-page-test/shared/geoip/dbip.mmdb`，root持有、目标UID只读，禁止可写或指向生产文件的符号链接。源摘要为 `145ce5dfb812d8de28547e6289bb5c5f8b3df2f1dea21d324d5b575e0431311f`，目标逐SHA核同；实际配置dbip_lite/max_age_days=45，并核metadata、目标UID读取及解析/缺库过期拒绝。不给假US、不访问外部IP解析、不放宽生产敏感目录。文件复制和目标解析本轮未执行。
5. 渲染 `__FASTCGI_INCLUDE__` 为目标root持有的include绝对路径；`__LIVEWIRE__` 用目标实际 `livewire-xxxxxxxx` 段。保留现有默认TLS/SNI拒绝server、80续期与旧8443。目标机 `nginx -t`、`php-fpm8.4 -t` 后仅00 reload已有服务；比较生产配置摘要保持。原站不重部署、不改任务。
6. root执行下面原生预检/安装顺序；资源参数先由00在实际主机合成视频测峰值。384MiB门槛为256MiB任务上限之外预留128MiB的初值，不能证明真实视频够用。不得为了让素材变ready提高主机规格或放宽生产预算。

以下命令由工程在目标Linux执行；本轮未执行。current必须已经指向最终候选，既有路径/权限保持，禁止跟随其他站目录。

```sh
set -eu
stage_release=$(readlink -f /srv/h5-page-test/current)
test -f "$stage_release/scripts/ops/staging_content.php"
case "$stage_release" in /srv/h5-page-test/releases/*) ;; *) exit 1 ;; esac
/usr/sbin/runuser -u h5_page_test -- /usr/bin/env APP_ENV=staging \
  APP_CONFIG_CACHE=/srv/h5-page-test/shared/bootstrap/config.php \
  /usr/bin/php8.4 "$stage_release/scripts/ops/staging_content.php" check
# 真实库/角色/UTC、媒体/storage/current/cache路径及关闭付款/邮件必须全部通过。
/usr/bin/systemd-analyze verify "$stage_release"/deploy/page-test-staging/h5-page-test-*.service \
  "$stage_release"/deploy/page-test-staging/h5-page-test-*.timer
for stage_unit in h5-page-test-media-process.service h5-page-test-media-process.timer \
  h5-page-test-media-stop-on-failure.service h5-page-test-content-publish.service \
  h5-page-test-content-publish.timer; do
  /usr/bin/install -o root -g root -m 0644 \
    "$stage_release/deploy/page-test-staging/$stage_unit" "/etc/systemd/system/$stage_unit"
done
/usr/bin/install -d -o root -g root -m 0755 \
  /srv/h5-page-test/ops/media-process /srv/h5-page-test/ops/content-publish
# 已有RUNNING必须先核实际进程组、素材状态、日志；本步骤不擅自删除。
test ! -e /srv/h5-page-test/ops/media-process/RUNNING
/usr/bin/touch /srv/h5-page-test/ops/media-process/ENABLED /srv/h5-page-test/ops/content-publish/ENABLED
/usr/bin/chown root:root /srv/h5-page-test/ops/media-process/ENABLED /srv/h5-page-test/ops/content-publish/ENABLED
/usr/bin/chmod 0600 /srv/h5-page-test/ops/media-process/ENABLED /srv/h5-page-test/ops/content-publish/ENABLED
/usr/bin/sync /srv/h5-page-test/ops/media-process /srv/h5-page-test/ops/content-publish
/usr/bin/systemctl daemon-reload
/usr/bin/systemctl enable --now h5-page-test-media-process.timer h5-page-test-content-publish.timer
```

持续范围已经本人确认，安装后两个timer随timers.target启动，不套旧45分钟/6素材门槛。`Persistent=false`不补触发离线期间的每分钟tick；应用每次检查已到期计划，对过期分钟仍按D03挂起，不擅自追发。实机重启恢复尚未验证。

## 运行、验收及准确停止

媒体每分钟单实例、每次最多1条。只读容量条件先看生产media unit真实ActiveState，再看MemAvailable：生产不是inactive/failed、内存少于384MiB或检查失败时，本次跳过、journal有理由，timer保留且不触发失败停用。只在条件通过后预检并持久写RUNNING，成功才清RUNNING。CPU25%、MemoryHigh192MiB/Max256MiB、Nice10/OOMScoreAdjust500仅限本unit。检查是瞬时判断，不能防止生产随后启动；实际并行峰值和长视频仍由00验，不把它称作全机资源协调。

实际失败/超时/终止时只移除测试站媒体ENABLED并停其media timer，保留RUNNING供排查；不拉起或停止PG/FPM，不关闭页面、不停止发布或生产任务。发布任务单独每分钟检查计划；异常留失败，下次按原幂等逻辑再查，不启动通用队列。`command_exit=0`配合`held/blocked`也不是发布成功。

```sh
# 状态与执行记录；仅检查测试站的两个任务。
systemctl list-timers h5-page-test-media-process.timer h5-page-test-content-publish.timer
systemctl show h5-page-test-media-process.service h5-page-test-content-publish.service \
  -p ActiveState -p Result -p ExecMainStatus -p ExecMainStartTimestamp -p MemoryPeak
journalctl -u h5-page-test-media-process.service -u h5-page-test-content-publish.service --since '15 minutes ago' --no-pager
```

健康证据需记录最终SHA、时间及具体对象：本人后台新上传→实际timer领取→ready/attempt/预览可读；新建未来计划→真实calendar执行→publication记录/正确分集上架→干净观众免费集完整解码与会员集拒绝；随后下一tick不得重复上架。`check`模式只读预检不是上述业务执行。发布日志只输出结果计数不输出UUID、标题、Token或URL；具体阻断原因由受保护后台/数据库另查。

对新站做一次实际重启后核timer enabled/active、同一计划不重复、未决过期计划不意外上架。备份必须包含现有新增内容、Owner/MFA和私密资料，并在独立目录/新库恢复核对关系及发布状态；本候选未完成这个新恢复，也不借11月2日旧空库恢复为新站背书。真实手机Safari/Android与Chrome视口模拟分记；公网测试域名才能供其他手机访问，Mac回环不能。

普通暂停只停所需专属timer，页面保留。导入维护时先停两个timer并等待在途结束；仅在确需中断时才执行下列service停止：

```sh
systemctl stop h5-page-test-media-process.timer h5-page-test-content-publish.timer
# 等待或按维护决定中止在途任务；中止媒体会保留RUNNING。
systemctl stop h5-page-test-media-process.service h5-page-test-content-publish.service
```

确认退出/素材状态后由00判断RUNNING的恢复办法，禁止盲删并重领。只有结束持续任务范围才 `disable --now` 两个专属timer，不能因验收结束自动禁用。回退只针对staging的Nginx/include、release指针及必要配置；旧探针模板会令普通菜单重回503，须先安排可用修复而非静默降级。保留新订单/权益/消费事实，不整库回灌、不重置attempted、不触碰生产。

## 本轮已核与未核

本机Nginx1.28.3（OpenSSL3.6.5，含SSL/SNI）对本候选完整模板做原生TLS和合成FastCGI检查：23个正常路径/方法转发、1MiB分块、Offset/Range/If-Range、伪转发头不进入应用、Host/SNI及dotfile/PHP拒绝、支付/回调503通过。真实a5 `route:list`确认菜单/API，不代表登录业务已过。PHP包装器语法、5份unit隔离静态检查及4项容量判断/检查故障合成测试通过。原生TLS使用临时自签证书/回环，不是公网CA证据。

复现命令（nginx须含SSL，openssl须支持req/addext）：

```sh
python3 scripts/ops/tests/check_staging_operations.py --nginx /absolute/path/to/nginx --openssl /absolute/path/to/openssl
python3 -m unittest discover -s scripts/ops/tests -p test_staging_media_capacity.py -v
php -l scripts/ops/staging_content.php
```

待00接入后核：Linux systemd权限/condition/真实资源峰值、目标GeoIP、内容迁移、本人会话及实际保存、真实媒体/排期、公网新路由、重启、独立恢复。05真实/r页与PageView候选另接；付款及官方交易、真实邮件、平台接收和手机未因路由放行而通过。发现模块失败记复现、影响、实际SHA与首败环节交00，不在验收重写模块。
