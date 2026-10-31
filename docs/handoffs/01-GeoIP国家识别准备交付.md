# 01 GeoIP国家识别准备（geoip-ready-1）

2026-11-01，基线 **fbb5f3a76d42bbf5e8f9cc4b86768cd61c5110f3**，独立分支codex/01-geoip-readiness；源码 **a07c5ec57670de290069ad45d028e86e4ef9c1b9**。仅本轮00授权的公共窄范围，接收后仍归00，不扩Identity永久所有权。

- ServerRegionResolver保四参构造及原显式local/testing直接loopback US测试语义，公网fallback经新DbIpCountryLookup；成功沿原契约country/trusted，否则ZZ/unresolved。只用原始REMOTE_ADDR；私有/保留/缺失/无效IPv4/v6不查询，任何国家/代理头或浏览器country无效，不猜US、不改Content许可。
- config/geoip.php三项：GEOIP_DRIVER默认disabled，GEOIP_DATABASE默认空，GEOIP_MAX_AGE_DAYS默认45；启用dbip_lite。年龄只接受整数/规范整数字符串1..45，非法不放宽。disabled在取文件路径前返回，无文件/网络操作。
- 文件须规范绝对路径（realpath逐字匹配，拒绝文件或父路径symlink/wrapper/非规范路径）、public之外、可读普通文件、1..64MiB。只读模式要求**无任何写位**（例如0444/0440，含拒绝组/他人写），打开前后stat身份/权限/大小/时间保持；每次Reader在finally关闭，不长期缓存或联网。
- 使用现成maxmind-db/reader 1.14.0；metadata v2/IP4或6、buildEpoch不能未来或超过配置天数。按00澄清仅校验Country格式：type为1..80可打印常规字符，独立Country标记，拒绝City/ASN混合；不是品牌认证或许可验证。只取country.iso_code两大写字母且非ZZ，绝不fallback到registered_country；损坏/不信/未知不输出原异常路径/IP或记录IP。

**最终11项117断言通过，0.253秒**：新增8项实际MMDB读取、IPv4/v6/registered_country区别、伪头/私网等、日期边界、路径/权限/public/损坏/大小、关闭/旧local、类型/无效ISO；仅加跑直接受影响原local边界2项、直连代理防伪1项。测试无RefreshDatabase/数据库连接、无HTTP/Web/队列/调度启动；Pint/PHP语法/diff检查通过，未重跑R1/邮件/全站/审计。首次命令从根调用artisan找不到相对vendor，改应用工作目录后才实际测试；不计产品失败。

合成fixture取官方MaxMind-DB精确commit `d1551916cff0712d12ff602c309234471b0402a2` 的GeoIP2-Country-Test.mmdb（17,300字节），Apache-2.0许可原文、来源及SHA256随tests/Feature/Contracts/Fixtures/GeoIp/提供。实读metadata.type=GeoIP2-Country、buildEpoch=1792695177；测试相对该epoch冻结时间。临时副本等长fault注入只作损坏/字段断言，仍由官方Reader解码，不自写解码器。**不是DB-IP实际当期库或真实IP精度/版权授权证据。** DB-IP品牌、原下载/许可/完整性及月更新步骤启用前仍归00/06核；[官方格式](https://db-ip.com/db/format/ip-to-country-lite/mmdb.html)、[许可/月更新](https://db-ip.com/db/download/ip-to-country-lite)。04另接署名，本任务未改UI。

证据docs/evidence/01/geoip-ready-1/含最终日志、8文件摘要、公共保护字节/来源。现有vendor按00锁仅install新增Reader、0旧包升级/移除，不改composer/lock；AppServiceProvider/RuntimeGuard/h5均原字节，依赖审计复用00已核结果。

请00串行接收源码及后续报告，核配置/type口径并统一留存。未配置真实站、未下载真实生产库、未查询访客IP、未打开GeoIP/新HTTP、未触数据库或旧环境，无进程需停止；既有环境数据/期限保持。本次是默认关闭的工程准备，不是公网地区或正式上线通过，用户无需操作。
