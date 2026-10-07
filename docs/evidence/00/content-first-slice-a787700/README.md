# 总控 02 候选复核证据

`review.json` 是结论索引，交接正文见 `docs/handoffs/00-04-02首段复核.md`。

- `initial-suite.txt` 与 `initial-concurrency-retry.txt` 对应 a787700，保留实际失败，不是全绿记录。
- `browser-reproduction.json` 对应初始页面；`latest-fffb88f/` 对应更新后的候选，F03 仍存在、F04 原场景已修。
- 页面复现使用原样候选脚本和表单控件，但 fetch 是明确虚构的响应。它证明浏览器构造的请求，真实后端后果由独立数据库用例核对。
- `ContentReceiptReproductionTest.php` 两项通过表示 F03 缺陷已成功复现，以及 F04 回归通过，不能解释为 F03 修好。
- 并发测试两次均停在子进程启动握手。启动耗时是待诊断因素，不据此声称发布业务一定出错；未加大超时或更改业务断言。

需要复跑时，先在最新候选的独立工作区按 `scripts/dev.py setup` 建立自己的环境和数据库，将数据库用例与同目录 runner 复制到该工作区 `.runtime/`，从该工作区根目录执行 runner。它沿用 dev.py 的专属 `_test` 数据库及测试保护，不连接主库或其他任务库。页面 builder 从工作区根目录运行，只在 `.runtime/receipt-ui` 生成虚构页面；本次临时 8310 服务已关闭。

00 未复制用户测试影片、员工密码或个人 Stripe 凭证，没有接入真实支付。

日志仅移除终端颜色控制码和行末空格，测试结果及失败内容保留。
