# R1-B真实公共PHP适配证据

2026-10-31。实际源码`a90310e1a1dade1c0abb52e0c10ad348aae7a3fc`，工具修复`4641c4bc64ba3e15cb50aca5a77ea7d3ef949795`；测试在相同文件字节的未提交状态执行，随后分开提交，摘要见manifest。

| 文件 | 实际检查 |
|---|---|
| adapter-tests.txt | 9项57断言；DI实际提供方、Available不是许可、空/旧notice、无C、伪marker/手造/clone/跨请求、拒绝/结束/过期、重放去重、临时失败Retry且必要账号/收据保持、浏览器伪成功422 |
| combined-regression.txt | 288项4235断言、23.75秒；Acquisition、Identity、AdminPanelIntegrationTest和RuntimeEnvironmentGuardTest同轮组合通过；含上述9项，不与专项相加 |
| contracts.txt | OpenAPI1.0.5及59例通过；公共PHP类型通过实际DI专项与上述组合覆盖，不把旧OpenAPI样例当新PHP端口完整验收 |
| composer-audit.json | locked audit公告0、废弃包0；依赖/锁文件未变 |
| verification.json | Pint/Python AST/diff、27迁移源码保持、原证据保持及自有运行环境收尾 |

根默认Unavailable提供方的缺模块环境3/17为00发布时独立证据；本轮显式加载Acquisition并验证真实绑定，不改公共测试将两种环境混同。这里不是全仓库测试或01-B可信页面/时序接线验收。PHP异常不向Identity传播，Retry不授权持久化capture或后台补采。

没有新人工入口、真实资格/账户或付款映射、外部广告调用或平台确认；两用途选择仍只保存不运行。详见[交接](../../../handoffs/05-C注册R1-B适配交付.md)。
