# 06 admin-ui-v2 收尾证据

候选源码：`be7bb4f7c5585418883b777ce2e6f2676690f5ff`；契约1.0.5。所有浏览器证据均为真实Chrome访问06本机loopback页面，账号和业务数据为06自己生成的测试资料。

| 文件 | 记录内容 | 边界 |
|---|---|---|
| `contracts.txt` | 契约1.0.5 / 59个有效与拒绝样例 | 本地schema validator；环境保留LibreSSL提醒 |
| `content-boundary-focused.json` | 9项/130断言：素材绑定、播放许可失效、计划撤销和调度竞争 | 在06隔离_test库执行；之后才重建03夹具 |
| `profile-password.json` | 后台资料页保存、旧会话撤销审计、新密码+TOTP重登 | 只含布尔/计数和状态码，不含凭据 |
| `content-browser.json` | 02已有23项浏览器结果 | 合成视频/SRT，不是真实剧集 |
| `billing-browser-29-pre-reset.json` | 03原29项浏览器结果 | 明确标记为_test库重建前结果；未冒称当前新夹具执行 |
| `acquisition-browser.json` | 05已有22项真实页面结果 | 传输关闭、没有外部请求 |
| `responsive-02-05.json` | 02/05两种视口共4项 | 320px和736px，无body溢出、主要入口可操作 |
| `responsive-03.json` | 03两种视口共2项 | 320px和736px，严格_test站，无价格/付款写入 |
| `audience-playback.json` | 公开目录、免费首集HLS、无签名403、第六集会员拒绝 | loopback US演示，2秒蓝色视频；桌面Chrome hls.js/MSE，不是手机或真实素材 |
| `dependency-lock-audit.json` | composer.lock摘要和同日02/03审计关联 | SHA一致、advisories/abandoned均空；未重跑联网审计 |

`.runtime`中的凭据、MFA、一次性码、数据库与媒体未进入本目录。03当前重建后的新合成夹具留给07手测；不要运行会刷新_test库的Feature测试或`admin-browser.py seed`。
