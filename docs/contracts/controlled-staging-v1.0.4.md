# 公共运行环境检查 1.0.4

2026-10-07。00 所有，响应 03-002 / 06-001。此版本增加公共 PHP 支持类与严格配置检查；既有 HTTP 路径、DTO、错误枚举、金额、权益和地区事实契约不变。接收提交及测试证据以 00 交接单和总台账为准。

## 调用约定

`App\Support\RuntimeEnvironmentGuard` 为无状态公共服务，在 AppServiceProvider 注册模块前注册并检查，在 boot 再检查。

| 方法 | 承诺 |
|---|---|
| `assertSafeRuntime(): void` | 检查当前进程已加载配置；不合格抛出只含固定大写诊断码的 LogicException。不连接数据库、SMTP、Stripe 或地区服务 |
| `controlledStagingOrigin(): string` | 每次先执行上述检查；只有精确 staging 且 h5.controlled_staging 严格为 true 才返回规范 HTTPS origin。其他情况抛异常，不返回空串或回退 local |

成功只证明配置符合此版本允许项，不证明公网 TLS、IP 国家、邮件送达、商户归属、官方支付或部署验收已通过。服务不缓存成功结论；再次调用会检查同一进程内改变后的配置。它不会自动重新加载磁盘 .env 或撤销已经发出的网络请求。

03 可在模块内将配置异常转换为既有 DEPENDENCY_NOT_READY；启动时仍拒绝错误配置。Provider 与每次 Gateway 操作都须检查，不能只在启动时查一次。

## 环境矩阵与配置

| 项目 | 精确规则 |
|---|---|
| APP_ENV | 只接受 local / testing / staging / production；配置、容器环境和 Laravel Env 读取到的 APP_ENV 三者必须一致。大小写别名、空值、未知名均拒绝 |
| APP_DEBUG、H5_CONTROLLED_STAGING、H5_SIMULATION、H5_LOCAL_US_TEST_REGION | 保留加载后的原始类型，必须是 bool。标准环境文本 true/false 由 Laravel Env 转换；junk、0、1 或缓存中的字符串 false 均拒绝 |
| H5_CONTROLLED_STAGING | 默认 false；只有 staging 可设 true。该开关不自动选择支付驱动、填凭据、生成可信地区或开放投流 |
| local / testing | 继续允许既有本地 HTTP、邮件预览和受限测试地区；controlled_staging 仍必须 false。保持现有 LocalModuleRegistry 限制 |
| staging / production | debug、simulation、本地地区必须 false，local_modules 必须空字符串；强制下列 origin、cookie、直连入口、邮件检查 |
| APP_URL | https://域名，仅默认443，可省略443及根斜线；返回时主机转小写并移除根斜线/443。禁止用户信息、查询、片段、非根路径、控制字符、其他端口、IP、localhost及其子域、尾点及非法主机字符 |
| 会话 | session.secure=true、http_only=true、same_site=lax；保持 Identity 的服务端身份、隔离 Cookie、加密、CSRF 和对象校验 |
| 代理 | trustedproxy.proxies 只能缺省/null或空列表。所有环境用 DirectConnectionContext 替换框架 TrustProxies，主动清空静态信任列表，不允许托管域名自动信任代理 |
| 实际非本地请求 | 必须由服务器 HTTPS 信息识别为加密、端口443、Host等于APP_URL主机；不合格在业务处理前400。Forwarded / X-Forwarded-* / CF国家头不能改变来源IP、Host或协议 |
| Acquisition | 非本地 provider=null / ready=false；D05 尚未冻结，保持禁用。local/testing 显式装载沿用已接收方式 |

使用配置缓存时，FPM 池和队列/调度服务须在**进程环境中显式提供同一 APP_ENV**。Laravel 缓存启动不会再读取 .env，仅在 .env 写 staging 不足以满足三者一致检查。生成缓存、停止旧 worker、重启新进程及验证旧 PID 退出由06部署流程落实；不能拿一次检查成功证明后续进程已换配置。

候选A仍为浏览器→直接TLS Nginx→Unix socket FPM。不启用 real_ip、Cloudflare 国家头、通配代理或代理自动判断；Nginx须白名单传递必要HTTP头并由真实TCP连接提供REMOTE_ADDR、由TLS入口提供HTTPS/443。上述中间件测试不代替最终Nginx/FPM实际配置和外部请求验证。

## 首个允许的邮件技术路径

本版批准的是已安装 SMTP 传输及强制 TLS 的配置结构，**没有选定供应商或开通账号**。发送域名、实际服务和账号由用户及06后续准备；真实送达另验。其他 API 传输须另经00审阅依赖和TLS/配置后扩展允许项，不能因 mail.php 出现 ses/resend/postmark 就视为批准。

- mail.default 指向真实命名配置。禁止旧 mail.driver、MAIL_URL/DSN 覆盖、未知参数和非SMTP叶子；preview/log/array/sendmail及未知传输在非本地均拒绝。
- SMTP transport必须smtp；scheme显式smtp或smtps；require_tls严格true。auto_tls与verify_peer缺省true，若出现只能严格true。禁止降级关闭TLS/证书核验和自定义stream选项。
- MAIL_HOST必须合法域名，不能是IP或localhost；PORT为1–65535、TIMEOUT为1–120秒的整数或规范数字文本。用户名与密码不能为空且无控制字符；不输出值。MAIL_FROM_ADDRESS须合法邮件地址，EHLO若配置须合法域名。
- `MAIL_REQUIRE_TLS=true`、`MAIL_TIMEOUT=10`为新增默认值。smtp表示必须成功升级到TLS，smtps表示直接TLS；只构造客户端不等于完成连接或发送。
- failover/roundrobin按实际已加载配置递归核对每条可达路径。只允许transport/mailers/retry_after，mailers为1–8个名称列表；最大深度8、总访问节点64、拒绝循环、缺项、内嵌配置和不安全回落。retry_after缺省60，若给出须1–3600秒。
- 现有样例 failover/roundrobin含本地/未审传输，不能直接用于staging。新增非本地组合配置先按上述约定修改并验证，不默默把错误回落当作SMTP成功。

## 当前仍未具备的能力

1. ServerRegionResolver未改：非本地仍为ZZ/unresolved。地区库来源、授权、版本摘要、更新和最大库龄尚未冻结；开关不能把请求变为trusted/US。
2. 本次不改Billing。现有Billing Provider/Gateway仍拒绝staging stripe_test；03须基于实际发布提交实现03-002，再交00/06复验。production/live仍禁止。
3. 默认不开Acquisition，不发广告，不改变D03/D05或任何订阅/退款规则。
4. 无新增依赖和迁移。既有数据和外部账号不复制到新环境。公开测试站仍待地区、03消费者、真实资源与外部验收，不因此文件发布而获准部署。

## 可复验入口

从当前工作区根目录，使用已有隔离运行器选择公共测试：

```sh
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Contracts/RuntimeEnvironmentGuardTest.php tests/Feature/Contracts/SharedInfrastructureTest.php
```

测试包含真实独立PHP启动：11个临时配置缓存场景和16个不使用缓存的环境变量场景；只用合成凭据和当前工作区测试库配置，无SMTP/Stripe/地区网络。另检查复合邮件、TLS客户端实际选项、规范origin、伪造来源头、HTTPS/Host拒绝及每次调用重验。测试缓存存入本工作区私有.runtime，结束删除，不写入普通运行的配置缓存。
