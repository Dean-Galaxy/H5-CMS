# AWS 部署准备第一步：仅离线候选

契约 [launch-prep-1](../../docs/contracts/launch-integration-prep-v1.md)；实现起点 `f10a97dcd85d110ba796fa0a79dbd19880087023`，业务契约1.0.5。本目录不是已部署网站，区域、域名、预算、Linux镜像和备份身份仍待00/本人决定。 本人已说明尚未买域名、预算未定；AWS注册选择美国不代表部署区已选。8GB/$44仅后续小规模试运营报价参考，非容量保证或采购授权。本轮不读取真实云凭据、不连接AWS/SMTP/DNS，不安装、申请证书、启动服务或访问数据库；不重开已关闭环境。

2026-11-01离线兼容增量依据[direct-test-1](../../docs/contracts/acquisition-direct-test-v1.md)和[geoip-ready-1](../../docs/contracts/geoip-readiness-v1.md)，源码起点`fbb5f3a76d42bbf5e8f9cc4b86768cd61c5110f3`：受控test-only工程待组合接收、production未放行；模板测试位保持false。 GeoIP显式`GEOIP_DRIVER=disabled`、`GEOIP_DATABASE=`、`GEOIP_MAX_AGE_DAYS=45`；本阶段固定45，拒绝启用driver、填文件路径或更改年龄（实际查询契约另允许1..45）。GeoIP查询reader不代表实际数据、许可、版本或主机已验证；不把AWS区或加库视为游客国家。旧“公网投流完全不可能”描述由此更新，不能据此直接启用。域名/region/预算仍留未选阻塞。

## 本地怎么使用

在本任务源码根运行；Python只使用标准库，无需Laravel依赖或数据库：

```sh
python3 -B scripts/ops/aws_preflight.py
```

默认读取仓库内无密钥 `deploy/aws/runtime.env.example`，不读取shell中的AWS/支付秘密、应用`.env`或旧runtime。输出 `mode=static-only`、`host_checks=not-run`、`network_actions=false`、`deployment_ready=false`，退出码 **2** 是预期阻塞：区域/APP_URL/SES端点/发件身份未齐、密钥尚未安全配置，以及GeoIP、公网Acquisition、正式注册告知、资源/主机及云恢复未接收。`template_valid=true`只说明候选结构/默认保护项符合这一阶段规则，不是实际主机启动检查。

需要看Nginx候选时，只在本区创建产物目录、使用保留示例域名做离线替换；不会解析该域名：

```sh
mkdir -p .runtime/aws-candidates
python3 -B scripts/ops/aws_preflight.py --domain staging.example.com --phase acme-bootstrap --output .runtime/aws-candidates/acme.conf
python3 -B scripts/ops/aws_preflight.py --domain staging.example.com --phase tls --output .runtime/aws-candidates/tls.conf
```

这两条即使成功写文件也仍退出2并保持deployment_ready=false。输出每次必须为新文件、0600，已存在即拒绝，不覆盖；同名重跑请另选新产物名。只允许仓库`.runtime`或实际临时目录中的`.conf`产物，拒绝`..`、符号链接及`/etc`/public等安装路径；父目录须已存在。macOS `/tmp`自身为链接时会拒绝，请用真实临时目录或上述路径。原入口 `render_nginx.py --domain ... --phase tls|acme-bootstrap --output ...`复用同一规则；其退出0仅表示写出候选，不是部署准入。

可通过`--env-file`检查新建的**无密钥**`*.env.example`副本。只支持固定未加引号的KEY=value子集，拒绝重复/未知键、插值/命令、符号链接、非普通/过大文件和真实`.env`。APP_KEY/DB_PASSWORD/MAIL_USERNAME/MAIL_PASSWORD必须留空；填值只报固定类别，不验证值。该工具不是Dotenv或RuntimeEnvironmentGuard替代品，不把示例复制到真实站启动。CLI域名和非空APP_URL须一致，HTTPS主机不得含路径、端口、IP或注入字符。SES端点须与候选区域匹配；这只比字符串，不证明该区支持SMTP或账号已批准。465配smtps、587配smtp并强制TLS；不放宽Guard。

## 直连HTTPS与首张证书的未来操作顺序

以下由00冻结具体主机/域名/费用变更单、本人授权后另派实际执行，本轮没有运行：

1. 在获批Linux上安装兼容锁定PHP8.4.26+的8.4.x、PG17/Nginx/FFmpeg/CA及ACME客户端，核软件源/版本/权限。DNS A指向固定IPv4，不开DNS代理/ALB/CDN；若发布AAAA，IPv6也须同等受限。SSH限运维来源，5432/FPM不公开。媒体保持 `/srv/h5/shared/media`，不加public别名；`CONTENT_FFMPEG/FFPROBE`指向核实的二进制。
2. **尚无证书时只用acme-bootstrap候选**，放入Nginx http{}下由运维核 `nginx -t`。该配置仅80端口的 `/.well-known/acme-challenge/` 从 `/var/lib/letsencrypt`供验证，其他路径503、未知Host444；无PHP、应用或443证书引用。它是独立替换阶段，不与TLS阶段同域名配置同时加载。
3. 获证书申请授权后，运维用ACME客户端的webroot模式，实际域名配 `/var/lib/letsencrypt`（例如certbot certonly --webroot）。HTTP-01验证需要80端口可达；不执行自动改Nginx配置的安装模式，不先加载缺证书的443配置。[ACME验证官方说明](https://letsencrypt.org/docs/challenge-types/)
4. 证书成功且文件路径/权限已核，再替换为tls候选，核 `nginx -t`及FPM配置后授权重载。模板为浏览器→直连TLS Nginx→Unix socket FPM，REMOTE_ADDR来自TCP；FastCGI保持显式请求头白名单，不信代理/国家头。新增仅PayPal验签必需五头，Stripe签名仍保留；不以头透传成功推定官方验签成功。
5. APP_URL必须与实际HTTPS Host/证书一致；FPM、唯一queue worker、唯一scheduler进程环境均显式staging，使用新安全配置缓存并核旧PID退出。现systemd的队列名h5_staging与示例一致。正式公开前必须核真实地区、SES邮件及账号和模块准入，不将公众站改为local/testing。
6. 设置ACME续期及成功续期后的受控Nginx重载；分别验证续期配置、失败告警、实际外部TLS。仅有证书文件或离线渲染不代表续期/HTTPS已通过。暂不提供云安装/重载/购买执行器。

## 真实邮件与默认关闭边界

环境示例不默认选AWS区域，也不填演算用SMTP主机、发件身份或Secret。获批后由07核同区SES发件域/DKIM、真实收件地址、SMTP区域凭据和sandbox/发送额度，再由另行安全录入流程写实际配置。现有SMTP强制TLS路径可复用，不能换SDK或回落preview/log冒充成功；SMTP不是客服收件箱。默认支付driver和新购provider均unconfigured、PayPal API=false、广告transport disabled/external=false、C/R1=false，任何启用都另经00/原模块准入。

本步没有更改Guard/GeoIP/正式条款/依赖或公共模块。`scripts/ops/backup_restore.py`会使用本机dev库并造测试夹具，**不能用于云库备份/恢复**。具体镜像、容量、桶、加密接收者及运维身份批准后再扩既有运维脚本；不在此步新建六套release/backup框架。停止/回退与集中验收仍见[主方案清单](configuration-checklist.md)，没有独立云恢复或公开站放行结论。

## 本步验证和交付

```sh
python3 -B -m unittest discover -s scripts/ops/tests -p test_aws_preflight.py -v
```

launch-prep-1原首步12项定向离线测试覆盖默认不联网/不执行子进程、不读取真实.env、保护项与输入拒绝、私有新文件/链接和覆盖拒绝、PayPal五头白名单、首张证书阶段及静态状态不会报部署通过。本轮direct-test/GeoIP只执行新增2项定向验证，原12未受影响项复用。未跑旧全套、本地服务/数据库、Linux nginx -t、真实TLS/DNS/SES/平台回调；后四类必须在受权环境另取实际证据。
