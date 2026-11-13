# 06 观众播放POST路由窄修验收

2026-11-13，受测修复源码 **`720c3a0f2e7dd6e14f75a3a7f5ec089543bf5ded`**；本地旧模板 `af4c54f716a73adea7ea19e008def3db8ad888db`（文档后续`5b9a2943f5657c6fced08ecdf174b9ad88b8f316`）。00云端首败版本b65e8309为总控回执，本轮未访问云端。具体代码摘要及10件静态文件名单见 [manifest](source-manifest.json)。

问题：静态前缀集合错误包含`/audience/`，其仅GET规则在进入应用之前拒绝04页面实际的`POST /audience/dramas/{UUID}/episodes/{UUID}/playback`，返回Nginx HTML403。影响免费与会员的正常播放授权请求；首败为边缘location，不是媒体文件或会员规则。复现：打开匿名剧集页后发送页面原播放POST，00现场403；06本机完整TLS原生模板加相同路径合成请求也403。上轮23项遗漏该真实页面动作，不能据其证明播放可用。

修复仅模板及跨模块fixture两文件：从宽静态前缀集合移除audience，以完整文件名正则精确限定实际10件css/js；其他/audience请求正常进入应用。没有针对一条业务路径特批、修改CSRF、权限、FPM、配置密钥或数据。

本轮执行Nginx1.28.3/OpenSSL3.6.5原生完整TLS回环：

- 先旧模板失败：[403回执](before.txt)。
- 修复后原23项+真实播放POST共24项到达合成FastCGI418，确认不含CSRF的请求也只是送入原应用入口，未作任何豁免。
- 10件实际静态文件GET200且逐字节相同，各POST403，防止脚本/样式被错误送入应用或开放写方法。
- 原1MiB分块、Offset/Range/If-Range及缺省、伪转发头、Host/SNI、dotfile/PHP、付款/回调拒绝检查通过。[结果](after.json)。

这些是边缘路由/静态文件证据，上游是合成418，不证明应用419、播放许可成功、实际解码或真机通过。由00单取源码、备份后精确部署staging vhost并以最终SHA复验真实页面动作。当前正常GET/菜单、媒体副本和已存Token不动；06没有SSH、部署、FPM或数据库操作。未重跑旧全套/资源专项。
