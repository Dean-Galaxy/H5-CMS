# AWS 候选配置与实施清单

配套[一页主方案](../../docs/06-AWS最小上线方案-20261031.md)；审阅基线 `2c2a2956c69cf687f237d2603d91a0f1201b4492`，2026-10-31。此文件是待00冻结的规格，以下新增脚本尚未实现，配置未应用；不在本轮执行安装、建库、迁移、邮件发送或公网访问。

## launch-prep-1第一步增量（2026-10-31）

以下旧方案对照保持2c2a295历史基线；新起点f10a97d的第一步已补无密钥env、纯离线预检/渲染、首ACME阶段模板和PayPal五头。[本步真实范围/用法](README.md)。旧表内“缺五头/待新增env”等是当时缺口，已由本步窄补；云发布/实际预检/备份框架仍未实现，后续须具体资源与身份授权再扩现有脚本，不把原六脚本清单当当前实施授权。

## 现有实现和最小补齐范围

| 现有文件/事实（该基线） | 复用方式、最低关闭条件及归属 |
|---|---|
| `deploy/nginx-staging.conf.template`、`php-fpm-staging.conf`、`h5-{queue,scheduler}.service`；`scripts/ops/render_nginx.py`、`check_runtime.php` | 06补Linux安装/目录权限与证书首次引导、最新环境模板；沿用TLS直连Unix socket。现模板只放行Stripe签名，PayPal适配要求五头：`paypal-auth-algo`、`paypal-cert-url`、`paypal-transmission-id`、`paypal-transmission-sig`、`paypal-transmission-time`，须显式透传及实际反向测试，不全量恢复传入头。 |
| `h5-platform/app/Support/RuntimeEnvironmentGuard.php`、`ServerRegionResolver.php` | 非本地强制HTTPS、无代理信任、SMTP TLS，Acquisition provider=null/ready=false，C/R1禁用；地区仅local loopback可合成US，其余ZZ。00协调受信GeoIP来源/授权/更新库龄和最小消费者测试；00/05另交公网投流开关及资格边界，06不削弱Guard。区域选在美国也不能把访客判US。 |
| `app/Modules/Content/Services/MediaStore.php`、`Http/MediaController.php`（下文app相对h5-platform） | 只支持本地路径，私有文件由应用鉴权/短签名响应，`no-store, private`；不是S3/CloudFront已接入。`ContentServiceProvider`每分钟`content:process-media --limit=1`后台执行，带防重叠；先保唯一scheduler，不另重复排转码任务。FFmpeg已有解码/重封装，不承诺自适应码率转码农场。 |
| `config/mail.php`、`app/Modules/Identity/Services/{Verification,RegistrationTerms}.php` | SMTP结构可直接接SES；普通注册通知当前同步发送，不因启动worker就有邮件outbox重试。R1打开时Verification仅允许本机preview，条款版本也是local/test；01/08/00须交公网实际告知/收据与邮件失败/重发体验的必要改动，不复制旧R1凭据/同意。 |
| `scripts/ops/backup_restore.py`、`docs/06-部署与恢复手册.md` | 原脚本调用dev本机PG并创建带FakeGateway的恢复夹具，不对云库执行。只复用dump/清单/独立目标/摘要原则；06另做非造数的云备份恢复脚本。GitHub代码留存不含数据、媒体或APP_KEY。 |

00冻结后06最小实现清单：`deploy/aws/runtime.env.example`（无密钥占位）；`scripts/ops/aws_preflight.py`（静态/主机/配置与身份预检，拒绝不符）；`aws_release.py`（固定SHA、阶段回执、迁移前备份和原子切current）；`aws_backup.py`（一致性窗口/加密/远端校验）；`aws_restore_verify.py`（独立新目标、全表/媒体及账务/发布核对，默认不外发）；`deploy/aws/h5-backup.{service,timer}`。有现成能力则扩既有脚本，避免重复实现；不引入Terraform/Kubernetes或新SDK作为前置。公共Guard/依赖/业务改动由00及原模块接收，清单不授权06越界实施。

## 候选运行配置（不是可直接导入的.env）

| 项目 | 最小值或核对要求 |
|---|---|
| 主机与边界 | 单区域Linux x86_64、固定IPv4；公网仅80用于ACME/跳HTTPS、443站点；SSH22仅运维来源，PG5432、FPM socket不公开。所有已启IPv6入口同样限制；未配则不发布AAAA。初始限制访问，正式公开另批。 |
| 依赖 | `composer.lock`/前端锁文件不更新；PHP约束实际为`~8.4.26`，安装兼容8.4.x并检查扩展；PG17、Nginx、FFmpeg/ffprobe、CA、备份加密工具。由00选发行版及签名软件源并锁版本，不能假定OS镜像预装符合要求或放宽PHP锁。 |
| 路径和权限 | `/srv/h5/releases/<SHA>`，`current`指向已验release；`shared/{storage,media,backups}`和独立`shared/.env`。public中无媒体/备份/.env别名；h5仅写必要目录。PG独立应用角色/迁移角色/备份读取角色，无应用superuser。web和worker的OS用户不持备份删除权。 |
| 应用与进程 | 先`APP_ENV=staging`，`H5_CONTROLLED_STAGING=true`仅属受控测试准入；FPM/worker/scheduler进程显式同值。`APP_URL=https://实际域名`、独立`H5_WORKSPACE`/Cookie/cache/DB_QUEUE，DB loopback/SCRAM；`APP_DEBUG=false`，simulation/local-US=false，local_modules空，C/R1=false，trust proxies空。正式production另接，不换环境名绕闸。 |
| 秘密 | 服务器独立APP_KEY、DB/SMTP密码；0600私密录入，进程配置缓存同样按密钥保护。APP_KEY另外加密托管以恢复密文，不每次发布重新生成。AWS控制台MFA/部署身份、SMTP发送身份和备份IAM身份分开，不用root Access Key；日志/命令行/Git不出现密钥。 |
| 媒体与任务 | `H5_MEDIA_ROOT=/srv/h5/shared/media`；`CONTENT_FFMPEG=/usr/bin/ffmpeg`、`CONTENT_FFPROBE=/usr/bin/ffprobe`，Linux默认本已匹配，预检实际二进制。4GB候选先FPM max_children=2（旧模板6须调），单worker/唯一scheduler；媒体limit=1及锁沿用。当前上传分片≤1MiB，模板请求上限2m；核multipart开销，不盲目开放2GB单请求。 |
| 邮件 | `MAIL_MAILER=smtp`、`MAIL_SCHEME=smtps`、`MAIL_PORT=465`、`MAIL_REQUIRE_TLS=true`、timeout10；`MAIL_HOST=email-smtp.us-east-1.amazonaws.com`仅在该区确认后使用，region变更同时换身份/SMTP凭据；`MAIL_URL`缺省null，verify_peer保持true。备选587必须`scheme=smtp`且STARTTLS成功，不用25，不回落preview/log。[端点](https://docs.aws.amazon.com/general/latest/gr/ses.html)、[TLS端口](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html) |
| 网址、签名及通知 | DNS/证书主机/APP_URL一致，TLS终止在Nginx直接TCP入口，不加Cloudflare代理/ALB或信客户端国家头。验邮箱验证、重置、播放签名使用真实HTTPS域名。PayPal webhook_url仅同站`https://域名/webhooks/paypal`；旧临时隧道与旧App/回调不复用，需03官方重新绑定/读回。 |
| 支付/投流初始值 | `H5_BILLING_DRIVER=unconfigured`、`H5_BILLING_NEW_PURCHASE_PROVIDER=unconfigured`、`H5_BILLING_PAYPAL_SANDBOX_ENABLED=false`（对应基线`config/billing.php`）；Acquisition transport disabled/external false，所有平台凭据空。后续00/03定向开启Sandbox，Meta/TikTok由00/05逐一接收；没有Live、广告外发或合成测试付款发送广告的默认许可。 |
| 备份与告警 | 建议起点RPO24h、暂留7个日备份，RTO需独立恢复实测后给出；媒体更新同批保留并控制磁盘余量。私有S3 Block Public Access、加密、版本/生命周期与额度按实际留存报价；备份专用IAM限制本桶前缀，不给PHP读取凭据。写备份与恢复/删除权限分离；Lightsail不假定已获得EC2实例角色，若需专用凭据需安全录入/轮转。若要求instance-profile则00另审EC2成本，不暗加AK。[S3私有控制](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-control-block-public-access.html) |
| 日志/停止条件 | 复用已审脱敏应用日志；Nginx模板无原始URL访问日志，另补固定码计数/进程失败、磁盘余量、备份龄、queue失败/积压、scheduler心跳和邮件退信告警，须有接收人。磁盘或突发CPU告警触发暂停新上传/扩容评估，非自动购买。告警服务若收费另列，不记录完整签名URL、收件地址、Cookie或token。 |

## 本人/07的账号和DNS动作

07先收集已有域名及权威DNS控制权、预算/区域、实际发件地址与能收信的客服/退信处理邮箱，以及Meta/TikTok业务资产/像素与授权范围；不让本人在聊天粘贴Secret。本轮不创建身份/凭据或申请审批。

获00和本人放行后：逐条回读DNS A及ACME记录；同区SES验证域名的Easy DKIM记录，明确DMARC与SPF策略。若采用自定义MAIL FROM，用独立子域配置SES指定MX/SPF，不能覆盖客服邮箱MX。[MAIL FROM](https://docs.aws.amazon.com/ses/latest/dg/mail-from.html)、[DMARC](https://docs.aws.amazon.com/ses/latest/dg/send-email-authentication-dmarc.html)

SES新账号每区域默认sandbox：仅已验证收件人/模拟器、200封/24h、1封/秒；先授权真实测试收件人闭环，再由本人提交事务邮件用途/网站和退信处理材料申请移出sandbox，获批前不能开放任意邮箱注册。SMTP凭据为每区独立发送凭据，不是AWS登录密码或通用Access Key。SMTP发送不提供客服收件箱；初期指定有人值守的真实收件邮箱接退信/投诉，保留SES抑制策略并确认处理负责人；若后续上SNS事件消费另列必要接入，不声称已自动同步业务状态。[Sandbox](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html)、[地区](https://docs.aws.amazon.com/ses/latest/dg/regions.html)、[退信处理](https://docs.aws.amazon.com/ses/latest/dg/monitor-sending-activity-using-notifications.html)

## 发布、停止和回退

获得具体资源/域名/费用授权且00冻结缺口修复SHA后，06先纯本地准备无真实秘密的渲染/预检与Linux脚本语法检查；业务验证只跑变更涉及项。云安装前出具资源数量、区域、月费公式、开口和DNS变更单。部署串行：新release校验锁依赖/权限→真实安全录入→受控DB迁移前备份→显式staging生成config cache及预检→nginx/FPM配置检查→切current并重启新进程，核旧PID退出。ACME首次申请先HTTP challenge引导，拿到证书后才加载引用证书文件的443配置；不能直接用缺证书模板启动。系统用户/包源/服务单元均固定，不用本机`dev.py start`管理云站。

每日备份在暂停上传/发布、等待在途处理结束的同一窗口完成：PG17 `pg_dump -Fc`＋全表摘要/迁移版本＋完整私有媒体清单＋代码/配置摘要，先本地公钥加密再上传唯一前缀并校验远端；APP_KEY/必要secret另保管。不能用磁盘快照代替数据库一致性证明；云备份成功不等于独立恢复。恢复只到新隔离实例/新库，网络出口/队列/调度关闭，核账务权益、发布状态和密文可读后才报告恢复结果。

暂停/结束先关闭新购/广告外发及新任务准入，等待在途写入，保持支付通知可持久收件或与03核准官方补投/对账办法，再备份最新事实、正常停queue/scheduler/FPM与本机PG；不能静默丢支付通知。代码回退仅迁移兼容时切旧release并保留当前库；不兼容就向前修复，禁止自动migrate:rollback或用旧dump覆盖新付款。停服务/停实例不应视为停止AWS计费；销毁实例/桶、释放IP和改DNS均须另审，保留备份不可自动清空。[实例计费与停止](https://docs.aws.amazon.com/lightsail/latest/userguide/amazon-lightsail-frequently-asked-questions-faq-billing-and-account-management.html)

## 一次集中验收清单（条件齐备后才执行）

- 固定实际应用SHA/迁移/依赖/配置摘要；独立云库和媒体、HTTPS Host/TLS、CSRF/员工MFA/对象范围/限流、无debug或秘密泄露，真实地区可核且伪造头无效。
- 实际SMTP TLS→指定真实收件箱→验证/找回链接→正确账号成功、过期/重放拒绝；核退信处理和发送限额，不能用SMTP accepted代替收件证据。
- 官方Sandbox付款/签名通知/重复幂等与取消退款仍按03清单，媒体hls.js及手机真机、未签名/跨号/下架拒绝另取实际证据；Meta和TikTok各自收件/事件类型及拒绝停发验证，不把测试付款送正式广告资产。真实文案/版权/地区与平台资格是独立前置。
- 云主机重启后worker/唯一scheduler恢复，过期发布不重复/意外上架；从离机备份到全新隔离环境恢复并核订单/权益/发布/媒体与密钥，不覆盖新支付；向07交真实HTTPS入口及逐步使用/停止说明。

以上仅未来集中验收入口，不是本轮测试通过记录；既有未变证据复用，不重跑旧站或全套历史异常。
