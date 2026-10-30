# 01-B CSRF与身份检查时序修复证据

2026-10-31。旧脚本基线1d17ac0，修复源码129c59a57f46baadacb83d5a265b9bd998c77593。新旧identity.js摘要见provenance.json；00私密探针只读核对，没有复制到本目录或提交。

- baseline-before-fix.txt：修改客户端前，先用新增的同一安全测试跑旧脚本，预期安全断言失败。实际次序me A→me A→csrf B→PATCH带B，fixture内模拟保存1条。不是实际用户/数据库事件。
- purpose-browser-fixtures.json：修后完整26类通过，包含原24类和新增两类。取csrf时切B，后续me识别B，零PATCH且清草稿；取A令牌、me返回A快照后切B，仅带A固定令牌的PATCH被合成服务419拒绝，模拟保存0条，不报saved/不自动刷新重放；显式重试取得B令牌后me识别B，零追加PATCH并清草稿。每类必要注册POST仍只有1次。
- registration-recovery-fixtures.json：原6类回程/普通模式兼容本次重跑通过。
- php-regression.txt：本次新跑Identity全目录及Contracts/RegistrationMeasurementTest、AdminPanelIntegrationTest、RuntimeEnvironmentGuardTest，138项1406断言通过，9.18秒。使用01专属PostgreSQL测试库；没有接入00当前新组合或05真实adapter，不代替组合复验。
- pint.txt及语法/diff检查通过；无共享契约、PHP业务、迁移、依赖或锁变更，未重新做依赖审计或共享结构校验，不引用旧结果冒充新跑。
- stopped-status.json：测试后仅01工作区正常停止，五项false；web/queue/scheduler未启动，其他窗口未动。

浏览器执行真实identity.js/CSS，HTTP全部拦截、合成账号/令牌。419由夹具按当前必要会话令牌校验模拟；此处不声称真实浏览器多标签+服务器端到端已验，交00接收后复核。26/6是本次新结果，旧24/6保持原证据。

复跑全部：执行tests/Feature/Identity/Support/registration-purposes.browser.cjs。仅选时序可用H5_IDENTITY_SCENARIOS=csrf-switch-before-identity,csrf-switch-after-identity；H5_IDENTITY_SCRIPT可指定从旧Git对象导出的测试脚本，用于旧版红/新版绿对比，不是产品模拟开关。
