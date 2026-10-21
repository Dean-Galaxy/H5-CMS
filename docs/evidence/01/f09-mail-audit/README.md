# 01 F09 邮件审计语义补修

日期：2026-10-21。基线 `56a1ca233ce8887da33f81fe83dc674d8e2076b0`；实现提交 `e5d33c25d056d210167d3b42abbd953f05ee7a0a`，分支 `codex/01-identity-maintenance`，契约 1.0.5 不变。

00 在最终审查中确认：实际本机配置为 `MAIL_MAILER=preview`，此前 StaffAudit 只识别 log/array，导致真实预览被误报已提交发送。本补丁修正该结论，原 f09 图像和源码清单保留为历史证据，不覆盖。

| 审计记录 mail_mode | 当前显示 |
|---|---|
| preview / log / array | 已生成本地预览 |
| smtp / sendmail / ses / postmark / resend | 已提交发送（送达未确认） |
| 缺失、null、未知、failover、roundrobin、其他值 | 邮件处理已记录（发送方式未确认） |

只解释历史记录中的方式，不从当前运行配置反推历史事实；任何行均不代表真实送达。组合方式未记录实际选用的底层发送方式，不能当作外发凭据。

## 验证

- 独立 PostgreSQL 测试库 `h5_2396db4f70_test`，端口 6269；14 项、112 断言通过，0.79 秒，原始文本见 targeted-tests.txt。
- 新增真实 `Verification::send → VerifyEmail notification → PreviewTransport → AuditRecorder → StaffAudit → Livewire 审计页` 检查；未使用 Mail/Notification fake。实际生成一份 eml 和一份 html，验证本地预览声明、审计 mail_mode=preview，以及页面不显示“已提交发送”和观众邮箱。测试 finally 只清理其 UUID 专属目录，且断言清理完成。
- 新增缺失/null/未知/组合/非字符串方式检查；即使当前默认配置变成 smtp，历史缺失值仍显示未确认。该用例只读审计，未调用 SMTP。
- 现有 smtp 手填审计用例继续验证“送达未确认”显示；不是 SMTP 实际发送或送达验证。
- Pint 最初发现导入排序，自动排序后 `--test` 通过；`git diff --check` 通过。无依赖、契约、迁移、密码或权限逻辑变更，沿用先前按固定版本记录的相关检查，不冒充本轮重跑。
- 网站/队列/定时/数据库/HTTP 已全部停止，见 stopped-status.json。开发业务库、其他任务环境和素材未修改；测试数据库由 RefreshDatabase 管理。

按 00 要求，本轮不重拍无关视觉截图。F09 仍开放，07 继续暂停；本补丁待 00 接收，不代表设计对照、真实邮件、官方支付或正式上线完成。
