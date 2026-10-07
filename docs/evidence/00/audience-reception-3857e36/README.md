# 04 最终交付的总控复核证据

2026-10-07。结论、固定提交与后续开工范围见 [00-11 接收单](../../../handoffs/00-11-04修复接收与共同起点.md)。这里的测试和 HTTP 记录由 00 在自己的隔离环境执行。

| 文件 | 含义 |
|---|---|
| review.json / source-manifest.json | 固定版本、边界和改动源文件 SHA-256；实测提交与 main 的应用/脚本相同 |
| candidate-tests.txt | 04 固定交付加已接收 01 文案，209 项/1914 断言通过 |
| integrated-tests.txt | 默认接入及 01 测试适配后，212 项/1940 断言通过 |
| activation-contracts.txt | 默认装载等公共边界 23 项/216 断言 |
| player-state-tests.txt | 运行 04 实际播放器脚本的 16 项受控事件/传输/HLS 替身检查 |
| player-race-probe.cjs / player-race-original.json / player-race-fixed.json | 00 独立 F06 探针，同一程序在旧版复现、在新版通过。仅模拟 DOM 和传输，不是实际断网播片 |
| contracts.txt | OpenAPI 1.0.2 和 47 个正反样例 |
| http.json / browser.json | 00 独立空库的 14 项真实 HTTP 及 IAB 页面观察；无真实视频重复导入 |
| migrations.txt / start.txt / stop.txt | 独立开发库升级、五项服务就绪和最后停止；数据保留 |
| main-start.txt / main-http.json | 主版本接入后的迁移/重启与 HTTP 结果；8204 仅只读检查现有预览入口 |
| identity-copy-http.json | 01 文案单独接收时的四项主站检查；其中基础首页是 Audience 合入前的历史页面 |

回归探针默认读取当前检出的 `h5-platform/public/audience/player.js`，以拒绝旧请求恢复播放为通过。第二个参数可指定播放器文件；再加 `expect-regression` 才以旧问题复现为通过。普通新版检查不要使用该参数。

日志只去除了终端颜色和行尾空白。真实素材、账户凭据、邮件正文、签名媒体地址和数据库均未纳入本证据。Composer 锁未变，依赖审计沿用 00-08 及 04 同锁记录；HLS 实体摘要本轮重新核对。官方支付、真实邮件、真实手机、非原生 HLS 解码及外部部署保持待验。
