# 06 PayPal本机准备证据

实测源码 `272b2ac1ae7b1d3fdb8a990ec3c20a5f810165e7`，应用公共起点 `fc81e576d1abb813c24d125bc0ddc8238b1f574d`。这些证据仅证明离线凭据工具；没有PayPal调用、数据库迁移或应用组合验收。

- `unit-tests.txt`：13项实际HTTP/临时文件测试，含跨来源/null Origin、CSRF、注入/重复字段、大小限制、符号链接、私密权限、端口占用与关闭。
- `browser.json`：实际Chrome 156，10项检查；页面/保存/不回显/关闭，无外部请求。不是手机实测。
- `empty-local-form.png`：实际空表单截图，无用户值或秘密；已人工查看。
- `receipt.json`：实测SHA、工具摘要和测试后8406/5906/8407未监听，新PG未创建，合成凭据已删除。

首次单元run有1项测试路径错误（GET请求了/save而非/），更正后通过。首次Chrome run暴露工具Referrer-Policy=no-referrer使Origin=null被拒绝；修正为same-origin且仍拒绝null/外站。紧接着重启遇TIME_WAIT，修正SO_REUSEADDR并加活跃端口占用拒绝用例；最后上述固定SHA重跑通过。失败不是PayPal业务缺陷，不隐去后把第一次运行称为成功。

复验前确保8407无别人的服务、没有本人已保存凭据；浏览器脚本发现已有凭据会拒绝覆盖。执行方式见主报告。浏览器测试仅用合成值，成功后会定向删除本次合成文件并关闭录入工具。
