# 06 公共运行契约1.0.4适配证据

实测源码/工具 **6dcf6038e964678a8261379736482a6200631f07**，基于00固定标签 **0c88f07d2238fee6ad41ea162152e4a85325c55d**，实际运行契约1.0.4。后续文档提交不改变应用/测试/ops/deploy。本轮只报告实际执行范围。

| 文件 | 本轮证据 |
|---|---|
| affected.txt | Contracts + Integration + Security，39项/622断言，16.76s；不是再跑293项全套 |
| runtime.json | 41个独立PHP/缓存/预检场景；安全正例、逐字段负例精确原因、开关API、缺进程APP_ENV拒绝、常驻探针退出和新进程拒绝 |
| backup-restore.json | 新唯一testing源库和第二PG实例；47表/42媒体摘要一致，68.30s；源/恢复后/发布后版本由实际config读取，付款为FakeGateway |
| restart.json / restart.txt | 真正停止本机web/queue/scheduler/PG，旧PID退出/新PID不同，普通开发库付款/公开集数/发布记录一致；CLI运行预检版本1.0.4 |
| security-http.json | 18个真实本机HTTP安全检查；local Host观察单列，不冒充公网Host拒绝 |
| audience-http.json | 8个真实HTTP观众流程；独立Cookie jar、preview邮件、未配置付款拒绝；不代表真机或真实邮件 |
| contracts.txt | OpenAPI1.0.4结构及57样例 |
| deployment-static.json | FPM/process APP_ENV、systemd前置预检、SMTP-TLS/超时及默认关闭开关的离线检查；没有实际FPM/systemd/nginx/TLS运行 |
| format.txt | 本轮修改PHP的Pint结果 |
| manifest.json | 固定提交、适用范围、执行命令、证据及修改工具文件SHA-256 |

所有环境探针的SMTP凭据都是合成值，仅构造配置和启动CLI，不调用发送；临时配置缓存仅在本工作区私有目录，结束清除自己的临时探针文件。普通开发库/密钥不被这些缓存用作外部配置。对运行环境的具体拒绝原因断言失败会终止探针，不以任意启动失败充作目标规则通过。

本机进程更换采用根dev.py的stop/start，停止前后核对自己pids元数据与数据库计数，最后通过check_runtime.php读取配置。服务器配置切换的手工步骤、真实FPM与systemd的旧PID/control group核对要求见现行手册；本轮没有把CLI常驻探针当作真正FPM。

终端记录只去掉ANSI颜色和行尾空格，原件保留在私有.runtime。恢复数据、APP_KEY、SMTP凭据、用户邮箱/密码、媒体文件没有进入本目录。旧 [4f1c309证据](../4f1c309/README.md)未被覆盖，尤其MSE、价格与原恢复结果仍属于88c2fef。
