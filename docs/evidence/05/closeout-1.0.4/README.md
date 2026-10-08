# 05 契约 1.0.4 本地收尾

2026-10-08。当前 05 独立 worktree 基于主线 `8405f40d6dec372db3ce49b267559ed190da558a`，普通合并提交为 `3902cfab7bda0d9f186cd0bca906e53437a5767d`。本次只同步 1.0.4 主线并复验现有 Acquisition 代码，没有新增业务代码或迁移。

| 检查 | 结果 |
|---|---|
| Acquisition 专项 | [acquisition-tests.txt](acquisition-tests.txt)：40 项 / 501 断言通过，8.98 秒 |
| 默认组合 | [full-default-tests.txt](full-default-tests.txt)：`H5_LOCAL_MODULES=` 显式清空，308 项 / 4844 断言通过，58.09 秒 |
| 契约 | [contracts.txt](contracts.txt)：OpenAPI 1.0.4，57 个正反样例通过 |
| 迁移 | [migrations.txt](migrations.txt)：Acquisition 与 Billing 迁移全部 `Ran` |
| 重启 | [restart-status.json](restart-status.json)：05 独立 web、queue、scheduler、database、HTTP 五项为 `true` |
| 运行绑定 | [runtime.json](runtime.json)：真实 StaffDirectory、ContentPromotableCatalog；归因 false/null；外发 disabled；无凭据 |
| 调度 | [dispatch.txt](dispatch.txt)：`Processed: 0; transport: disabled` |
| 后台与落地 | [http-checks.json](http-checks.json)：health 200；后台入口到员工登录页；`/r/{code}` 503 |

已检查的功能链路包括：02真实已发布美国片单筛选、像素登记/启停/多人共享、链接生成/停用/旧选择回显、03 `PaymentSettled.v1` 去重与来源快照、Meta/TikTok本地模拟发送和失败重试。相关隔离测试数据只在本工作区测试库中生成；没有复制其他工作区 `.env`、数据库、素材或凭据。

本轮没有发现05自身确定缺陷，也没有修改D05来源窗口、点击覆盖、旧链接、像素变更、落地目标或04隐私插槽。默认Acquisition仍关闭，只有自己的local/testing环境显式 `H5_LOCAL_MODULES=acquisition` 才启用；真实Meta/TikTok账号、真实事件、官方支付、真实地区和外部测试站都未使用。

后续人工依赖保持最小范围：投流同事提供两平台专用测试像素及授权令牌/测试事件代码，并在D05和隐私衔接冻结后配合逐笔核对事件接收与去重。用户或00仍需决定D05规则和04网站插槽；本轮不需要退回01、02、03、04或06。
