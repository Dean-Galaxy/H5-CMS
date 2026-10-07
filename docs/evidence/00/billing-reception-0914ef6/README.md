# 00 对 03 F05 修复的独立接收证据

2026-10-07。03 候选 0914ef6，源码 ac52cdc；00 最终组合 da19235，main 接收 eec4e11。完整版本和范围见 [00-08](../../../handoffs/00-08-03修复接收与后续开工.md)。日志仅去除终端颜色和行尾空白，检查内容与结果保留。

00 使用自己的复核工作区、8300/5630、独立开发库与测试库，没有运行或清空 01/02/03 的数据。测试数据库始终校验工作区标识和 `_test` 后缀。32 份 Billing 源文件摘要与交付一致，最终 main 应用和共同 HTTP 脚本与实测组合完全相同。

| 文件 | 结果 |
|---|---|
| candidate-tests.txt | 原样候选 169 项、1527 断言通过 |
| f05-probes.txt、checkout-parameters.json | 2 项、29 断言通过；截获的优惠请求已省略冲突字段，没有调用 Stripe |
| integrated-tests.txt | 最终默认接入组合 173 项、1596 断言通过 |
| initial-integration-tests.txt | 保留初次 172 通过、1 项 Identity 旧测试假设失败；01 修复条件后最终整套通过 |
| initial-acceptance-test.txt、contract-tests.txt | 00 测试样例的邮箱验证字段被模型保护导致 403；改为明确准备已验证测试观众后，共同测试 18 项、146 断言通过。后续新增的入口测试纳入最终完整回归 |
| contracts.txt、format.txt、dependency-audit.json | 契约 1.0.2 与 47 样例、格式及锁定依赖审计通过 |
| shared-http.json、billing-http.json | 8300 实际 HTTP：共同 5 组及账务 6 组通过；只有本地邮件预览 |
| start.txt、migrations.txt、stop.txt | 复核环境实际运行、4 项账务迁移及最终停止，保留数据 |
| main-start.txt、main-http.json、main-health.json | 8101 主工程新增迁移、启动与接收后 HTTP 检查 |
| review.json、source-manifest.json | 固定提交、范围与结果，以及本轮涉及源码的 SHA-256 |

主要组合用例已进入 `tests/Feature/Contracts/AcceptedBillingPlaybackTest.php`：普通非会员拒绝第 6 集、样例付款后的真实权益、取消/部分退款保留原期、累计全退撤权与免费集保留。付款适配器和 1024 字节媒体为 testing 样例，不能称为 Stripe 官方或完整视频端到端结果。

Python 契约工具出现 urllib3/LibreSSL 兼容提醒，结构与样例检查实际通过；不把该 Python 环境当作外部 TLS 支付验证。支付适配器使用锁定 PHP SDK。本轮未调用真实 Stripe、未发送外部邮件或广告、未对外部署或收费。
