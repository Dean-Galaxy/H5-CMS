# 03 PayPal运行接线本地证据

代码 `35bd488d8ae8abd7c5ec7d27aceeb724ad715280`；配置依00 fc81e57，锁文件依e330bbf。详细范围与官方未验项见 [交接](../../../handoffs/03-PayPal运行闭环-2026-10-27.md)。

| 文件 | 结论与边界 |
|---|---|
| billing-contract-tests.txt | 最终323项/4347断言通过；18项PayPal运行测试。测试库隔离，所有PayPal HTTP Mock |
| contracts.txt | OpenAPI1.0.5、59样例通过 |
| dependency-install.txt | 安装00锁定依赖，Livewire4.4.8；没有改锁 |
| dependency-audit.json | advisories和abandoned为空 |
| startup.txt / migrations.txt | 仅03工作区8203/5603启动正常，迁移Ran；不清库、不复制数据 |
| http-smoke.json | 真实loopback HTTP health200、PayPal未配置503、浏览器取消CSRF419、未注册相邻路径404 |
| offline-readiness.json | 真实离线命令报告未配置、未联网、官方付款未执行；没有任何凭据值 |
| source-manifest.json | 本次18个源码/测试/迁移文件的SHA256及代码提交 |
| flow-final-tests.txt | 较早阶段15项/381断言通过，晚于最初链路测试、早于最终新增3项，不能冒充最终源码的单独结果 |
| flow-tests.txt | 更早阶段20项/247断言（8项运行+12项API），保留历史 |
| initial-flow-tests.txt | 初始6通过2失败；Mock错误返回200空数组而实际操作应204，修正夹具后通过 |
| initial-regression.txt | 初始302通过3失败；两个模块夹具和一个00共享夹具未显式选stripe，按新购三态修正，不放宽产品默认 |

最终新增退款差分测试曾把默认desired_renewal=null误断言为true；其语义为尚无用户停止/恢复意图。已修正为“原null不变”，同时检查实际renewal_enabled=true、零取消操作、无权益撤销，最终完整回归通过。真实HTTP首次探测将不存在路由预期为419，纠正为404；实际CSRF证据来自已注册浏览器取消路径。上述是测试预期纠正，不是调整产品以绕过检查。

所有日志仅去ANSI控制码及行尾空白，未删除失败结果。官方Sandbox账户资格、merchant API权限、实际HTTP/签名/付款/周期/取消/退款没有执行，不能用这些Mock记录替代。锁依赖审计无公告也不等于生产安全或正式收款验收。
