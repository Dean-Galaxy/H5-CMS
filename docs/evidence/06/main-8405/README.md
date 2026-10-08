# 06 主线 8405 轻量收尾证据

本目录绑定文档基线 `8405f40`、应用接收提交 `a25dd8f`、契约1.0.4。

- `affected.txt`：06 worktree 实际运行 `run_acceptance.py tests/Feature/Contracts tests/Integration tests/Security`，43项/661断言通过。
- `runtime.json`：06 worktree 实际运行44个受控环境场景，包含缓存、拒绝码、空凭据状态、日志脱敏和数据库身份。
- `backup-restore.json`：06 worktree 实际运行独立备份恢复，47表/42媒体、3个未付账户、同一目标对象、旧账户不变。
- `manifest.json`：版本、命令、边界和未执行外部条件。
- 00-18 已另行记录92项/2568断言和44探针的总控复核；本目录不把两次测试数量相加。

本轮没有真实Stripe、SMTP、HTTPS、FPM/systemd、真机、异地恢复或Meta/TikTok连接。恢复付款为testing FakeGateway，探针禁用外部网络函数。