# 06 持续测试站候选验收（2026-11-13）

实际受测源码 **`af4c54f716a73adea7ea19e008def3db8ad888db`**，00指定起点 `a5f7691a680b71e0a03aedfa35b18a67d1b0628d`。随后本次报告、摘要与交接提交只增加文档/证据。逐文件SHA-256见 [manifest](source-manifest.json)。没有把旧373/5677、47/919、4/85或旧媒体/恢复报告算入本候选结果。

| 检查 | 实际方法和结果 | 边界 |
| --- | --- | --- |
| 正常菜单/前台路径 | Nginx1.28.3+OpenSSL3.6.5原生TLS，23个方法/路径全部到达合成FastCGI的418响应 | 含实际a5路由表的staff-accounts/content-settings；不是应用200、登录或保存成功 |
| 上传/媒体头 | 1,048,576字节完整到上游；Upload-Offset、Range、If-Range非空值保留，缺省头不发送 | 未上传到实际应用、未解码媒体 |
| 边缘拒绝 | dotfile/PHP404，支付和回调含付款尾斜杠503，伪XFF不转发，错误Host连接关闭，未知SNI由服务端拒绝 | 临时自签证书/回环；未知SNI检查关闭客户端主机名比对以验证服务端实际拒绝，不当公网证书通过 |
| 资源条件 | 4项unittest，含生产active/activating/unknown、384MiB阈值、缺unit/超时/坏meminfo保守跳过及合成正常读数 | 模拟systemctl和meminfo，不是Linux/cgroup或容量测试 |
| 运行包装器 | PHP语法通过；审读cached staging、current/storage/media、独立DB角色/库与UTC以及关闭付款/邮件检查 | 本机没有目标/srv及数据库；实际check/media/publish未执行 |
| 原生unit归属 | 5份静态检查通过：路径/UID不混生产、独立timer、无PG/FPM启停/通用队列 | 未运行systemd-analyze verify、systemd权限/故障/重启实测 |

原始脱敏输出：[路由](nginx-results.json)、[资源合成](capacity-results.txt)。本轮临时Nginx/合成上游均由测试退出清理；没有云访问、数据库读写、素材搬运、Token读取、平台外发、真实扣款或采购。

复核时修正了准备材料里的三个易误判点：员工/设置路径按真实route:list取值；未知SNI必须测服务端拒绝；现有check_runtime.php不支持--require-db-utc，改由新包装器核实际UTC。上述是测试/操作材料修正，不报作模块业务故障。

## 交00实际接入

执行入口在 [持续测试站手册](../../../../deploy/page-test-staging/README.md)。先串行接02内容迁移及05真实/r/PageView候选，再以最终新SHA检查受影响项；不能拿当前路由测试替新组合放行。常用网址为 https://staging.sparklingshort.com/admin 和 https://staging.sparklingshort.com 。

00回执的主机MemTotal962272KiB/MemAvailable508556KiB/无Swap及生产media timer waiting决定了本候选初值：媒体单实例CPU25%、MemoryHigh192MiB/Max256MiB、OOMScoreAdjust500/Nice10；生产unit忙或MemAvailable少于384MiB即跳本tick。条件只是瞬时检查，生产之后仍可能启动；实际合成视频峰值与并行风险由00原生核，不以这些数字承诺视频容量。资源不足跳过不会关闭网站或停timer；处理失败才停本测试站media timer，保留RUNNING，其他站点/服务不动。

GeoIP只使用00确认的现有DB-IP文件，复制至测试站独立只读路径并核摘要/metadata/过期拒绝后才启用实际解析。内容迁移期间先停两个测试站timer并等在途结束；Owner密码/MFA、现有测试资产和Token保留。新版本无需重新建库或重复迁入身份。

以下本轮均**未通过/未执行**，只交具体实施方案：Linux原生任务及资源峰值、目标GeoIP、02内容迁移、实际用户会话/后台保存/排期、游客完整解码/许可拒绝、真实手机、实机重启、当前新增数据独立恢复、05平台接收、真实邮件和官方支付。00已接收的本人登录及资产保存是既有事实，不等于这些新增范围通过。普通会员/登录流程如因关闭支付/邮件由应用返回依赖不可用，记实际首败交00分派，不关Guard或造地区消错。

现站持续运行范围已有本人授权；任务验收后不自动关正常菜单或删除新增数据。回退仅目标配置/版本，保留新付款/权益/发送消费事实，禁止整库倒灌。当前配置未启真实扣款，不作跨地区合规结论。
