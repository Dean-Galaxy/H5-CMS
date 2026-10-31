# 国家识别准备 geoip-ready-1

2026-11-01，00分派独立必要上线缺口：ServerRegionResolver当前公网始终ZZ，不能用AWS所在区/客户端国家头代替游客国家。只准备本地文件查询，默认disabled，不启旧环境、下载真实生产库或放行版权地区。

- 复用RegionResolver/ResolvedRegion；只读直连REMOTE_ADDR，忽略代理/CF国家/浏览器国家参数，IPv4/v6私网/保留/缺失拒绝。原local loopback+显式local_us_test仅local/testing行为原样，生产不得模拟。
- 最小选型为DB-IP Country Lite MMDB＋现成maxmind-db/reader纯PHP包（00维护composer/lock并审计），无需向外查询访客IP或新增GeoIP API账号。免费库精度/覆盖较低且月更新，不代表法律居住地或版权授权证明；未知仍ZZ，地区权益沿Content已有规则。后续只有实际误判/流量需要才评估升级，不预购服务。
- 新geoip配置默认driver=disabled、database空、max_age_days=45；启用dbip_lite须本机规范绝对只读普通MMDB文件、public之外、可读且无组/其他人写权限、体积有界，拒绝wrapper/symlink/不安全路径。读取数据库buildEpoch与country库metadata，未来/超过45天/格式损坏/未知IP/非两大写字母或ZZ均返回原ZZ/unresolved，不猜国家、不记录IP或原异常路径；driver关闭不打开文件/不联网。每次lookup资源正确关闭，不构建城市/指纹/国家头回退。
- 授权01仅在独立分支修改现ServerRegionResolver的公网fallback、新Support/DbIpCountryLookup、config/geoip.php及专属Contracts测试；原constructor四参数保持兼容，不动00正在改的AppServiceProvider/RuntimeGuard/h5配置或其他模块。00串行接收这些公共文件所有权，不因此把根配置权永久转给01。
- 针对性验证实际MMDB读取、可信peer/伪造头无效、缺失/过期/未来/损坏/关闭保持未知及旧local限定；可用官方Apache许可的合成Country测试fixture，须明确源码/许可/摘要，不能把真实全量库放Git。不能用硬编码国家表/自写MMDB解码器/网络API替代库。
- DB-IP Lite使用CC BY4.0并要求用其结果的网页带链接署名。04在现网站footer只按geoip.driver=dbip_lite增加固定链接“IP Geolocation by DB-IP”到https://db-ip.com，复用现样式；disabled时不展示，不加脚本/新布局。正式启用须实际数据许可、数据期与维护更新步骤经00接收，实际云部署另验。

官方：[数据/许可/月更新](https://db-ip.com/db/download/ip-to-country-lite)、[MMDB country.iso_code](https://db-ip.com/db/format/ip-to-country-lite/mmdb.html)、[MaxMind PHP Reader](https://github.com/maxmind/MaxMind-DB-Reader-php)。工程自检不等于正式地区/授权/公网通过，范围不扩展用户已选国家/采集/支付政策。
