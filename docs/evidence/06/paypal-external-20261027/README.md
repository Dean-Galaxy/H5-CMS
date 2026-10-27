# 06 实际外部 HTTPS 回调通道验收

2026-10-27。本人在00明确批准临时Cloudflare仅`/webhooks/paypal`；本机交付提交`172433ab32afd04f7c5250d67e6e98b78370d557`，实际业务应用仍`a82b831ba5d03064d7d1564a93a5b201298571ce`。本轮新增工具源码摘要见manifest，未改业务和原回调桥。

`acceptance.json`记录15项实际HTTPS请求、5项透明转发核对以及前后9张账务表计数，全部符合预期。来自本机的请求实际经公共Cloudflare HTTPS入口返回本机桥，不是直接请求localhost，也不是手机测试。Python系统默认CA和主机名校验开启，没有TLS绕过。

- GET首页、后台、配置、metrics、凭据、员工API、媒体、回调路径全部404；POST后台和带查询参数回调404。
- 缺五头400、Cookie/Auth403、错误Content-Type415；响应空正文，没有Set-Cookie/Location。
- 完整格式**合成**POST经过同一桥，到真实03应用8406，实际收到未配置503；临时观察器只匹配随机合成transmission-id，记录正文SHA256/长度与头摘要，不记录原文、签名值或其他请求。
- 保留UTF8与JSON空白的原始正文摘要一致；五个PayPal头摘要一致；转发仅Host、Content-Type、Content-Length及五头。伪造地区/转发IP/Origin和Cloudflare附加头均不向应用传递。
- 普通库九类账务事实前后均0，PayPal off、新购unconfigured。未读取本人凭据，未调用官方PayPal API；不是官方验签通过。

独立二进制cloudflared2026.10.4使用干净环境、/dev/null配置、固定Host、no-chunked，metrics仅127.0.0.1:8409。启动日志中空配置提示及功能DNS超时已记录；其后QUIC连接成功注册且上述HTTPS实际通过，不隐去启动告警。原始日志不入Git。

临时URL仅保存在未跟踪0600私密运行文件，公开acceptance只记URL摘要。00已明确接管本次活动窗口供07同App注册Webhook；桥与隧道仍在运行，关闭责任交00，尚无关闭回执。关闭后不能继续用旧URL登记Webhook；重开必须更新同App地址。
