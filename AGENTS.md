# H5 多任务开发共同规则

## 基线与范围

- Git 根目录是 `/Volumes/KESU/H5`；应用统一在 `h5-platform/`。独立工作区可有不同绝对路径，不得回历史目录建立第二套主工程。
- `原版/` 永久只读、不跟踪、不导入旧业务数据，不复用其中密钥、认证或支付逻辑。真实密钥、素材、依赖、缓存、数据库、邮件预览不进入 Git。
- 先读 `docs/H5首版业务规则确认稿.md` 和 `docs/多任务开发规划/00-总计划.md` 至 `03-进度与交接台账.md`。保留已有变更。已确认业务规则优先，未确认事项不得冒充事实。
- 01–04 本地组合及 01/02 投流只读提供方已由 00 接收；当前起点 h5-acquisition-readers-v1、契约 1.0.3，见 00-14 和台账。05/06 已由用户启动，05 原交付因 F07 退回且未进入 main。D05 未定，外部测试站未放行；旧标签不移动，接口放行不等于新任务启动授权。

## 文件及事实归属

应用内路径相对 `h5-platform/`：

| 任务 | 归属 |
|---|---|
| 00 | 根配置、依赖及锁文件、bootstrap、公共路由装载、app/Contracts、app/Data、app/Enums、公共 app/Support、tests/Feature/Contracts、docs/contracts、根 AGENTS.md；总台账与串行合并 |
| 01 | app/Modules/Identity、身份配置、routes/modules/identity.php、resources/views/identity、tests/Feature/Identity；观众、员工、认证、权限与审计基础 |
| 02 | app/Modules/Content、routes/modules/content.php、内容资源/视图/迁移及 tests/Feature/Content |
| 03 | app/Modules/Billing、routes/modules/billing.php、账务资源/迁移及 tests/Feature/Billing；唯一付款、订阅、权益事实 |
| 04 | app/Modules/Audience、routes/modules/audience.php、网站布局样式/翻译/观看进度及 tests/Feature/Audience |
| 05 | app/Modules/Acquisition、routes/modules/acquisition.php、投流资源/迁移及 tests/Feature/Acquisition |
| 06 | tests/Integration、tests/Security、tests/Browser、deploy、scripts/ops、部署验收文档 |

01 首次创建共享骨架；00 接收后转交共同文件所有权。模块可增加自己命名的迁移，禁止改已采用的迁移。跨模块查询与写入经过契约，不复制会员或付款判断。

## 契约变更

- 候选契约只有 00 接收后才能冻结为 v1。缺字段、公共依赖、权限枚举等变化，写 `docs/contract-requests/<编号>-<主题>.md`，说明当前版本、建议、消费者、兼容性和验证；由 00 发布版本后同步。
- 未接入模块必须显式 `DEPENDENCY_NOT_READY`。普通运行不可假造会员、媒体、付款或发送成功；测试桩只在 testing 或受限制的 local 模拟，生产启动拒绝模拟。
- 浏览器状态变更用会话、CSRF、服务器身份和对象范围校验。观众不能成为员工。金额为 USD 最小单位整数，时间为 UTC ISO 8601，发布显示北京时间，权益区间为 `[from, until)`。
- 不对外暴露持久媒体地址、支付凭据或不必要个人信息。关联号与审计使用脱敏字段白名单。

## 工作区和交付

- 每工作区独立端口、PostgreSQL 实例/数据库、测试库、会话/缓存、队列、素材和密钥；禁止默认共用可清空的数据。使用根 `scripts/dev.py`，不复制其他工作区 `.env`。
- 00 独占维护 main 并串行集成，模块在独立分支工作。禁止覆盖回主目录、强制重置同事变更、同时部署同一测试站。
- 用户于 2026-10-07 在 00 明确授权：以后发现问题，00 可以直接向对应的已启动子任务发送修复指令，要求补测试、重新交付并复验，无需逐项再请示。此授权针对现有分工内的修复协作，不代替创建新任务、改变业务规则或对外发布所需的授权。
- 交付 `docs/handoffs/<编号>.md`，按台账模板记基线/提交、契约、文件与数据归属、迁移依赖、真实与模拟边界、验证证据、操作说明、外部缺项及 00 接收事项。只提交明确属于任务的文件。
- 实际启动、适当测试、契约校验、迁移检查和依赖审计后才声称模块自测通过；本机预览、真实邮件、官方支付、组合验收与正式收费分别记录。
- 不替用户申请公司认证或采购；可独立继续的工程工作不中断。真正影响当前步骤的缺项才提问。
