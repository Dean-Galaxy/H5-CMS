# 00 公共后台与员工接线1.0.5证据

固定源码和共同标签：`46bd7cece7212a5db676056bc5cedac09fd2d8c3` / `h5-shared-v1.0.5`。记录日期2026-10-21。`source-manifest.json`逐项绑定132个相关源文件、测试和契约文件；归档时与该提交字节一致。

- `identity-integration-tests.txt`：Identity、AdminUi、AdminPanelIntegration、SharedInfrastructure和AcceptedAudience，94项/746断言通过。
- `contracts.txt`：OpenAPI1.0.5结构与59个正反样例通过，员工创建8位合法、7位非法；观众注册与重设仍12位。
- `livewire-browser.json`：共享焦点补丁7a48236的真实Laravel/Livewire HTTP夹具11项通过；该提交后相关JS、标签组件及夹具没有变化。
- `local-browser.json`：同一焦点补丁后的原公共浏览器夹具17项通过；仅证明公共交互，不代表业务页面。
- `dependency-audit.json`：本日锁定依赖审计无advisories/abandoned/filter；manifest核对依赖锁与已审计版本一致，无新增或升级。

终端颜色、行尾空白及外围空行已规范化，测试内容未改。先前两项失败为测试cookie准备及字段筛选问题，已修正后完整重跑；没有放宽生产鉴权来通过测试。

此包不证明完整新版后台、实际资料页浏览器改密、官方Stripe、真实邮件、地区识别、广告外发或外部部署通过。01实际改密复验单独交付，06需在最终同组合SHA验收UI01–UI11。
