# 03 共同契约 1.0.5 追加验证

2026-10-21。按 00 指令，在已交付的 `12a9d75` / `aa25fd1` 之上正常合并 `46bd7cece7212a5db676056bc5cedac09fd2d8c3`（标签 h5-shared-v1.0.5），合并提交 `2d7487a25ec755e35e6fbc5f64f2770b30de024b`；追加测试工具最终源码 `1b22eafa3aa0b8e573b8721095b0b891f0a3451b`。Billing 应用及视图与原交付逐文件不变，仅增加浏览器定向模式；未复制环境或重跑无变化的账本全量测试。

- [真实浏览器](browser.json) **10 项通过**：正常密码/TOTP、本站头像实际加载、三标签、连续 ArrowRight/End/Home、旧美元值、失败保存保持、放弃恢复、窄屏和零脚本/服务器错误。头像及其他外域请求均为 **零尝试/零完成**；[执行记录](browser-run.txt)。
- [只读存储核对](browser-storage.json) **8 项通过**：仍为价格版本 2、1234/321；原两笔账务完整摘要不变、客服备注仍仅一条。未创建新价格或模拟付款。
- [契约](contracts.txt)：OpenAPI 1.0.5 / **59 样例通过**；保留原 LibreSSL 环境提醒。公共密码/profile功能由 01/00 验证，本轮不重复声称完成其浏览器改密验收。
- [当前状态](status.json)：03 普通 8203 / PG5603 五项就绪；临时 8303 已停，私有配置摘要一致。无新依赖、迁移或账务源码变化，沿用本日同一锁文件的审计。
- [源码摘要](source-manifest.json)、[结论绑定](verification.json)。原 112 项账务/组合安全、25 项最终专项、29 项完整浏览器及原数据核对保留在 [上一阶段证据](../admin-ui-v2/README.md)，不重复计数。

完整 UI 流程已在同一隔离测试库写入测试价格并保存一条备注后，追加模式使用同一私有夹具账号进行真实登录：

```sh
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py start
H5_PLAYWRIGHT_MODULE=/path/to/existing/playwright node h5-platform/tests/Feature/Billing/Support/admin-browser.cjs --shared-smoke
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py verify-ui
python3 h5-platform/tests/Feature/Billing/Support/admin-browser.py stop
```

这不是普通库的验收数据创建入口。端口、数据库、账号和 MFA 仍受原严格夹具检查，真实 CSRF/认证不绕过。窄屏截图等待侧栏真实收起动画结束后捕获，不修改页面样式伪造布局。

最新截图：[桌面](shared-owner-prices.png)、[窄屏与本站头像](shared-mobile-prices.png)。原截图中未加载的外域头像已被公共补丁解决。Stripe 官方仍未配置/未验证，无真实收费、外部部署或广告发送；00 接收和 06 最终同组合验收仍待进行。
