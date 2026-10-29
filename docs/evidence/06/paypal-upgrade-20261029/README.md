# 06 2026-10-29 保数据升级与回调传输证据

实际运行和测试代码：`ca279acad01793203c7976154acca9fd3e71c248`，业务契约 1.0.5。前一运行版本为 `cfd24cbb6fe42ab959bd547d7ca70a02a8f2ae35`。报告在另一独立工作区提交，实际运行 HEAD 不随报告提交变更。

[逐项脱敏结果](acceptance-safe.json)来自本轮实际执行，包含五项服务健康、迁移、13 张账务表计数、45 张业务表完整行摘要相等判断、42 个媒体文件保持、15 项 HTTPS 请求及 5 项通知透传检查。没有复制真实账务行、密钥、临时公开 URL、原始数据库或媒体。

只读快照用 PostgreSQL `default_transaction_read_only=on`、UTC，将每张表的 `row_to_json` 文本排序后取 SHA-256；比较完整行包含时间戳，不只比较数量。分别保存 before、after-upgrade、after-reconcile、after-scheduler、after-window 五阶段。显式对账 CLI 额外禁用 curl/stream HTTP 传输函数；实际 scheduler 日志另有 2026-10-29 02:29、02:30、02:31 UTC 的 `billing:reconcile DONE`。未做网络抓包，不将此表述为抓包证明。

私密原始快照、归档、配置摘要及检查程序在实际06 `.runtime/06/upgrade-20261029/`，仅本机留存。外部窗口逐项回执及字节/头摘要在 `.runtime/paypal-sandbox/window-recheck-20261029.json` 与 `external-upstream.json`。观察器只记录本轮随机合成 marker；原文有空白和中文，五项 PayPal 通知头为合成值。上游实际返回 503，直达8406同样503，API-off 应拒绝处理；并非官方签名成功。

完整数据库归档可读取目录，不代表已将本轮数据库恢复到另一环境。历史373/5677、其他候选的恢复/HLS结果和00-35的437+单项重跑均没有计入本轮通过数。官方回调登记、开启购买及新账号付款由00接管后另取证。
