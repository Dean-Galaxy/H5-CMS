# 01 SES邮件接入最小提案（待00确认）

2026-10-31；基线 **2c2a2956c69cf687f237d2603d91a0f1201b4492**，分支codex/01-mail-readiness-proposal。仅公开源码盘点及AWS官方资料核对，**未改产品/配置、未运行发送或测试，未读取.env、真实库/邮件token**。维持邮箱密码＋邮件链接验证，不增加六位码或新认证系统。

## ① 已可复用

- Verification、Viewer及Fortify已串普通注册通知；验证为**30分钟HTTPS签名链接＋邮箱hash＋单次nonce＋同一账号登录**，重发轮换nonce使旧链接失效；匿名点链接先登录再继续，改邮箱使旧链失效，重放409。必要验证不授予会员。
- IdentityServiceProvider强制邮件URL使用APP_URL及其scheme；不信请求Host。找回密码走viewers broker，30分钟有效、60秒broker节流、单次token，重置撤销旧会话；忘记密码/重发另有每身份每分钟1、IP每分钟10限制。
- mail.php已经提供Symfony SMTP、强制TLS/证书校验/超时；非local Guard只准入受审SMTP叶子，拒绝preview/log降级。**接通SES传输本身仅需配置，无需SDK、依赖或新SES认证代码。** 本轮仅静态确认；ViewerTest和RuntimeEnvironmentGuardTest已有相关断言，未重跑、不冒称真实送达。

## ② 仅00/06配置与外部准备

| 归属 | 最小接入内容 |
|---|---|
| 00/06 | 独立HTTPS环境，R1/C本机开关关闭；APP_URL固定规范域名、稳定APP_KEY、安全Cookie及现有可信Host/直连TLS规则。AWS若用ALB/代理终止TLS，当前Guard禁代理，须00/06明确适配或选直连TLS，不能随意信任转发头。域名/APP_KEY变更会影响原签名，先固定再发信 |
| 00/06＋07安全录入 | MAIL_MAILER=smtp、MAIL_SCHEME=smtp、选定区域的SES SMTP endpoint、587/STARTTLS、MAIL_REQUIRE_TLS=true、verify_peer保持true、MAIL_URL为空、From/显示名/EHLO域名、短超时；区域SMTP专用凭据安全注入，不上聊天/Git，不能用AWS控制台密码或把API secret当SMTP密码 |
| AWS/DNS | 发件域/地址验证及DKIM DNS；核选定区域sandbox状态。sandbox只能向已验证收件人/域等受限发送；向任意用户发需该区域production access。退信/投诉处理与发件身份由00/06安排，应用不冒称已有送达回执采集 |

SES要求TLS，587支持STARTTLS，SMTP凭据按区域且不同于AWS登录/API凭据。[AWS SMTP要求](https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html)、[TLS连接](https://docs.aws.amazon.com/ses/latest/dg/smtp-connect.html)、[sandbox/正式准入](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html)。本提案未进入AWS、配置DNS或申请准入。

## ③ 01真正需要的窄补丁（建议00先确认两项）

| 已确认源码影响 | 最小关闭条件／目标及必要测试 |
|---|---|
| **普通注册邮件异常误成注册失败**：R1关闭时ViewerRegistrationController委托Fortify，CreateViewer先提交账号，Registered同步邮件异常发生在自动登录前；最终通用500，普通identity.js无R1回程保护，用户可能重提已存在账号 | 在**已提交账号后的邮件传输失败边界**保账号/未验证事实与可恢复登录/重发提示；不吞创建/数据库或其他事件业务异常，不假报已发送。目标Identity Verification/ViewerRegistrationController、账号view/两译文（若需）、普通表单回程的最小接线；复用既有身份JSON，不自改公共schema。隔离故障注入核账号仅1、未验证/无员工权、可登录重发、不重注册；另核正常通知与R1预览限制不变 |
| **找回密码发送异常破坏统一回应**：IdentityController::forgot只统一broker返回码，SMTP异常直接500；未知邮箱却accepted，存在按故障响应区分账号的缺口 | 只在传输失败处保现有中性accepted响应，改“若账号适用，请查看邮件；未收到可稍后重试”等不承诺送达的提示；内部仅安全类别/请求号记录，不写邮箱、SMTP异常正文/凭据或token。目标IdentityController及必要Identity服务/测试。注入真实存在/不存在/节流/传输异常核同响应形状，保broker60秒及接口限制；重置/失效/单次旧用例复用，不扩全套 |

重发已有账号时传输失败目前通用500但不会假报成功，nonce事务回滚保旧有效值；如00希望友好503可并入同一传输错误提示边界，**不另建重试队列/发信平台**。SMTP超时可能已被接受，统一标“未确认”，不自动连发。Verification现有verification.sent审计含mail_mode，后台已区分“本地预览”和“已提交发送（送达未确认）”；不把SMTP接受当收件箱送达，密码重置链目前无等价送达流水。

**边界不合并：** R1开启只可preview/testing-array，限制不删；其本机测试条款/收据绝非正式协议。普通路径R1关闭，目前不要求正式条款接受/不写R1收据；正式主体、服务条款/隐私文本及接受留痕要求须本人/00核定，再另给最小实施范围，不能借SES复用本机文本或开启分析。外部上线总放行仍归00/06。

## ④ 正式发送前本人只需给非秘密信息

确认网站规范域名、DNS管理方、发件地址/显示名/支持联系地址、SES区域、是否已申请脱sandbox及预计事务邮件量、受控收件测试邮箱、正式条款/隐私主体与文案负责人。07/06指导安全录入区域SMTP凭据；**不把密码、API secret、验证/重设链接贴聊天**。集中人工再分别记录：SMTP接受 → 实际收件/垃圾箱 → 同号点击成功/过期旧链/重发 → 找回重置。现在没有实际发送/送达/点击验收结论。

交00一次审阅两补丁范围，确认后才开发；未动公共mail.php/Guard/config/dependency、原分支/旧数据/运行站。无新服务进程或真实邮件动作，用户本轮不必重复测试。
