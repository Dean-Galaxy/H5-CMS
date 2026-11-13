# 06 HLS精确静态入口验收

2026-11-13，实际受测源码 **`9e8ef48c1d7e01ac2f36653084266cf02ffff82d`**，本地起点affaafdc3dac2f9d4ceadc7edcef2ca25e4ef8c6；[文件摘要](source-manifest.json)。00现场反馈当前853dcb4c播放页GET `/vendor/hls.js/1.7.3/hls.min.js`404，其他观众脚本200。首败为播放器依赖加载。00随后云端只读确认正式release的锁定脚本存在且摘要相同，staging文件不存在，故有两个独立原因：测试站遗漏生成产物，且旧模板不识别精确vendor文件静态入口。只修其中一项仍不可用。非原生HLS播放受阻，不能用清单/分片HTTP已过替代脚本加载与解码。

核对事实：页面 `h5-platform/resources/views/audience/watch.blade.php` 引用精确本地URL及 `sha256-oS5+4c1kpp3NsxQVfkXa/LpwW/sLFEC3k1yyZdN0Qj4=`；生产原 `deploy/nginx-viewer-preview.conf.template` 已有同一版本静态规则。a5/b65的Git树不含该生成依赖，本区亦没有；不是本次源码提交会自带文件。只读使用00本机现有公开脚本 `/Volumes/KESU/H5/h5-platform/public/vendor/hls.js/1.7.3/hls.min.js`，619692字节/SHA256 `a12e7ee1cd64a69dcdb314157e45dafcba705bfb0b1440b7935cb265d374423e` 与原锁一致。未下载、安装、修改原版或复制任何凭据/素材。

窄修仅模板及原生fixture两文件：新增精确location=，只服务该hls.min.js；GET/HEAD允许，POST403，明确JavaScript MIME，继承no-sniff与no-store/private。不开放/vendor目录或.map，不修改应用CSRF、FPM或数据。

原生Nginx1.28.3/OpenSSL3.6.5完整TLS回环只运行新增HLS专项，未重跑既有24路由/资源全组：

- [旧模板失败](before.txt)：真实HLS请求落合成FastCGI418，不是静态200；与00应用404分开记录。
- [新模板通过](after.json)：GET200与真实文件逐字节相同，HEAD200且空体，POST403；MIME/no-sniff/缓存头符合现行边界。
- 真实04播放POST仍到合成上游418，原CSRF/Origin头完整转发，不豁免权限或CSRF。
- 相邻vendor文件、map及目录没有变成静态暴露；PHP/dotfile404。

可复现命令（既有二进制及公开依赖路径，缺文件/摘要不符明确停止，绝不下载）：

```sh
python3 scripts/ops/tests/check_staging_operations.py \
  --nginx /private/tmp/h5-staging-tls-nginx.lwWODV/nginx-1.28.3/objs/nginx \
  --openssl /opt/homebrew/bin/openssl --hls-only \
  --hls-file /Volumes/KESU/H5/h5-platform/public/vendor/hls.js/1.7.3/hls.min.js
```

00负责把正式站已核的公开文件独立复制至staging部署产物，不共享inode、不改正式、不将第三方压缩vendor提交Git。原install_browser_assets.py --check同时检查hls.min.js和LICENSE：脚本619692字节/上述摘要，LICENSE1338字节/SHA256 ca8773cf798c7ed997d4dd7c8e23c348699f8d5b7462636694cc14de6cda12db。源LICENSE尚须00实核，不因脚本存在假定许可证已齐。复制完整锁定产物后，从目标release执行 `python3 scripts/install_browser_assets.py --check`，通过且Nginx精确规则就绪才交浏览器；此检查不联网。目标云文件/MIME和原生部署、干净Chrome点击后的MSE解码/分片与手机结果由00随后记最终SHA；本轮没有云部署或真实解码，不借其原生8秒媒体处理结果作为本次浏览器通过。

## 防再犯（持续补记，关联00统一索引）

本问题同时包含发布产物缺失与静态入口缺失，不因其中一项修复关闭整项。每个新release在交浏览器前执行既有 `python3 scripts/install_browser_assets.py --check`，核JS和LICENSE均符合锁；再对页面实际script URL核200、真实摘要、JavaScript MIME及SRI。生成文件不在Git中，不能只看源码归档、页面HTML200或媒体清单HTTP。保留本次HLS窄fixture及POST拒绝检查；最终仍须实际点击播放验证非原生HLS脚本执行与媒体解码。本记录保留失败及待验状态，收到00统一编号后原位关联，不重复编号。

### 同类后续：内容管理静态依赖再次遗漏（2026-11-13）

00在40b8589实际确认/content三个library资源404；与本记录同属“未从真实页面依赖核发布产物及静态入口”的根因类别。HLS单项修复没有覆盖其他菜单，此次是同类再次发生，不能只写成新文件404。沿本类别关联 [内容窄验与防再犯](../staging-content-static-20261113/README.md)，不造重复编号。新增从Content library Blade实际asset引用自动发现依赖的检查；实际员工四tab正文仍须00接收候选后验证，静态通过不得关闭完整业务阻断。
