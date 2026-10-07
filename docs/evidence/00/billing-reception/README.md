# 00 独立复核 03 候选

2026-10-07，候选 48af857811ab86fa6a14f4e8e653a8564bdee9b6，源码 d68147f58bca64f3d87f4744c17f24b96f9c9d9d，契约 1.0.2。结论与修复要求见 [00-07](../../../handoffs/00-07-03候选复核.md)。

| 文件 | 内容 |
|---|---|
| [review.json](review.json) | 固定版本、源码摘要校验数量、隔离配置、结果及 F05 的证据限度 |
| [candidate-tests.txt](candidate-tests.txt) | 00 重跑完整候选，159 通过 / 1299 断言 |
| [reception-probes.txt](reception-probes.txt) | 00 额外两项检查：组合授权通过，参数互斥检查失败，29 断言 |
| [checkout-parameters.json](checkout-parameters.json) | SDK 拦截结果，只保存问题所需字段，不含凭据或用户数据 |
| [BillingReceptionTest.php.txt](BillingReceptionTest.php.txt) | 00 额外用例源码，以文本保存，不自动加入 main 的测试发现 |
| [run-probes.py](run-probes.py) | 按指定工作区读取自己的 dev.py 配置后运行额外用例 |
| [contracts.txt](contracts.txt) | 47 个契约样例验证 |
| [http.json](http.json) | 00 真实本机 HTTP 结果，邮件仅预览，Stripe 未配置 |
| [start.txt](start.txt) / [migrations.txt](migrations.txt) | 独立服务启动与四项 Billing 迁移状态 |
| [dependency-audit.json](dependency-audit.json) | 本轮只读依赖审计，无已知通告或 abandoned 包 |
| [stop.txt](stop.txt) | 复核后停止 00 临时服务，保留数据 |

00 复用自己原有隔离复核目录，名称仍含 02，但运行的应用类已核实从该目录候选源码加载；没有读取 03 或主工程数据库。共享依赖程序按相同锁文件只读复用，应用、测试、环境、数据库与素材独立。31 个候选文件摘要在运行前后均匹配，候选工作区无业务源码变更。

首次启动的受限沙箱不允许本地端口绑定，误被现有启动脚本显示为端口占用；核对无监听后经工具批准在本机启动，以上为随后真实完成的结果。新增组合用例第一次遗漏接口所需的 play_session_id 字段，修正 00 自己的用例后通过；候选实现未改。F05 参数检查持续失败。

本轮无 Stripe 账号调用、无真实扣款或退款；额外测试中的付款适配器、SDK HTTP 和媒体字节均明确为 testing 桩。参数问题由真实 SDK 序列化与原始仓库兼容性记录支持，不冒充官方平台错误复现。依赖审计通过、样例通过或本地组合通过均不等于官方支付验收。
