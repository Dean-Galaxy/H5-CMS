# 03-002 本地验证证据（契约 1.0.4）

固定源码 `ef170ed2f2981f5e0bf3d382322d562d215515f6`，基线 `h5-shared-v1.0.4` = `0c88f07d2238fee6ad41ea162152e4a85325c55d`。03 独立工作区，HTTP 8203 / PG 5603，测试库 `h5_785df0be15_test`。00 尚待独立接收，不据本目录宣布外部测试站或正式收费放行。

- `full-tests.txt`：Feature + Integration + Security，312 项 / 4889 断言 / 61.16 秒通过。已有 runner 绑定自己的测试库、只在测试进程清空旧模块覆盖，没有修改私有 .env；各测试串行运行。
- `focused-tests.txt`：49 项 / 1907 断言 / 15.65 秒通过，覆盖 SDK、旧请求兼容、链接安全、运行环境与离线诊断。缓存/无缓存 staging 探针是独立 PHP 启动，安全正例保持实际官方 SDK/Curl，只读配置且网络函数禁用。不能将其写成官方支付或 staging 全链路通过。
- `initial-tests.txt`：首次 32 通过 / 2 失败 / 300 断言。旧兼容正例缺少远端 Session 返回地址字段，补齐测试样例后通过，产品检查没有放宽。初次失败属于过程记录，不是最终源码结果。
- `start.txt`、`migrations.txt`、`runtime.json`：本机正常停启及当前服务状态。已采用共同基线的 Audience/Acquisition 四项迁移，本候选没有新迁移或改旧迁移；未清空开发库。
- `http.json`：六组真实本机 HTTP 检查，含会话/CSRF/对象范围/未配置拒绝及本地邮箱预览，不是浏览器人工、真实邮件或公网 HTTPS 验收。
- `local-config-check.json`：实际默认未配置，退出 1 为预期；JSON 标记官方未执行与无网络，不能把配置就绪等同官方验证。
- `contracts.txt`：OpenAPI 1.0.4、57 样例通过；保留既有 LibreSSL 警告。`dependency-audit.json` 为当前锁定依赖只读审计，无公告/弃用；没有安装或升级。`format.txt` 为全部 13 份变更 PHP 文件的只读 Pint 检查。
- `source-manifest.json` 固定应用、契约与运行器摘要；`verification.json` 固定命令、结果、未做事项及其他证据摘要。

日志仅移除终端颜色控制字符、行末空格及多余的末尾空行；无密钥、签名或完整付款报文。账号、素材和数据库未复制。公司 Stripe 和真实 SMTP 未配置，官方 Checkout/Test Clocks/通知/退款测试均未执行；可信美国来源仍未就绪，未部署或收款。
