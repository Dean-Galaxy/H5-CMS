# 03 PayPal商户绑定修复证据

固定源码 `518c425c3001ee04f24d5b5f79fafabdeb6e61b5`，共享起点 `ef321908e53965e79f2c4f3f5bca32f17ded00ac`。范围、实际操作和证明边界见 [交接](../../../handoffs/03-PayPal商户绑定修复-2026-10-28.md)。

| 文件 | 结果 |
|---|---|
| billing-contract-tests.txt | 最终337项/4433断言通过；PayPal全部HTTP为Mock |
| contracts.txt | OpenAPI1.0.5/59样例通过 |
| dependency-audit.json | 锁依赖advisories/abandoned为空 |
| startup.txt / migrations.txt | 仅03隔离工作区正常启动，200700绑定表迁移Ran；没有绑定真实商户 |
| http-smoke.json | 真实loopback health200、未配置PayPal503、浏览器取消CSRF419 |
| offline-context.json | 真实本机API关闭、无商户/凭据时可离线返回null上下文；没有网络动作或商户验证成功 |
| offline-readiness.json | 默认离线诊断仍未配置，没有启用支付 |
| source-manifest.json | 本次九个源码/迁移/测试文件hash与固定提交 |
| preliminary-focused-tests.txt | 中间阶段24通过3失败，控制台单行JSON上多个逐行匹配期待不适用；改为解析完整JSON断言。不是最终源码结果 |

其他开发中间失败已定位为共享Webhook URL Guard未合入、测试用Webhook ID不符合现有资源ID格式、旧收件夹具未加入显式绑定/官方注册读取。最终正常合入共享Guard并更新隔离夹具；没有放宽实际配置/签名/绑定门槛来通过测试。全量最终回归覆盖所有旧收件、Stripe和付款行为。

源码Pint和最终diff空白检查通过。日志仅去ANSI和多余行尾空白。没有访问06密钥、复制配置、修改06服务或创建实际PayPal对象；用户实际核对的回执不存在于本证据目录。本地夹具中的来源确认不代表真实人工核对。00已报告的OAuth200/Balances403不由本Mock报告证明；官方新绑定/产品/回调/付款仍待验证。
