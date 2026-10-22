# 06 F09 / 658f407 实测证据

应用 `658f4078e3a2e943b617aeda6bb7c7e265dee1ac`，契约1.0.5；原型`bc5a24d63b7e23126bad9bb5519ea0e9fa8825cf`。全量和组合日志文件名中的6f2b87d为其真实旧候选SHA，不能改标。日志为原工具返回的末段（含最终数量/耗时），不是全部测试项清单；仅移除ANSI颜色和行尾空白，完整私密回执保留本任务运行记录。报告：[06最终组合验收](../../../../06-F09最终组合验收报告.md)。

- [环境和来源摘要](metadata.json)、[配对索引](comparison.csv)、[复演步骤](reproduce.md)。Content 138张实际/142张原型，Identity 16对，Acquisition 16个完整srcdoc参照；数量是截图数，不是测试数。原图未裁剪/拉伸。Billing内部pairs.json保留自己的视口/实际扩展说明。
- `content/flow`是74项真实流程及34条几何观测；`content/application`/`prototype`是多视口同主数据状态；`release-range`明确区分真实35/35及8组响应夹具。全局零对象sync是显示夹具，其余scope取消是实际服务端数据。
- `acquisition-browser.json`为实际完整50项原回执（从本任务工具日志恢复原JSON）；`acquisition-dirty`33项；`acquisition-errors`20项。本机显式启用，外发disabled，不把合成聚合当真实付款。老导出外壳参照图未加入最终配对；`acquisition-prototype`重拍完整srcdoc，与原50项实际图片配对。
- `identity`22项；管理弹窗两端都选投流/启用；真实姓名/邮箱和审计行数不同，只比较结构。`test-account-retirement.json`记录临时两个账号通过真实管理UI退役。
- `billing/design`23项在改价前；`billing/flow`29项真实表单；`billing-verify-ui.json`为其后8项只读核对。所有付款/退款都是明确testing夹具，official_payment_verified=false。
- `audience/result.json`13项真实解码/断网/下架；`first-run-assertion-mismatch.json`保留测试原先误等403导致失败。最终只接受有效期内409/STALE_VERSION和新观众入口404/NOT_FOUND、媒体0请求，不放宽任意4xx。
- `recovery.json`独立PG恢复及两次发布结果；`restart.json`自有服务真实停启、内容摘要不变。备份SQL、密钥、MFA、真实媒体不进Git。

## 设计结论与人工看图范围

F09未关闭。配对采集完整度不等于每张已经人工批准。06实际逐图查看的最终图包括：Content桌面basics、375深色release-confirm、375×500深色upload-review与cover-candidate；Identity手机manage；Acquisition手机深色payments。此前模块自审和00/08审阅仅作来源，不冒充06逐图查看。

确认差异：375发布确认/结果卡`.ct-dl`被单列覆盖，已由00回派02；封面候选布局已由00/08确认并派02。真实英文简介、多分类/排序、角色邮箱、测试环境列、未画的订单/客服细节，按必要业务扩展登记，不能据字段多寡宣布失败或整体像素通过。

**每行comparison.csv默认是“已采集，待逐状态判定”，不是pass。** 同数据主对象为35集、33就绪（8失败/12缺失）、后续10集公开/暂停；真实底层有额外合成剧、真实时间/文件名/审计，因此涉及背景或长表密度的整图比较不可用。短屏原型通过撑高root保持内容，而真实dialog保留操作区及正文滚动；需看实际滚动指标/交互回执，不要求复制原型溢出机制。

`migrations-current-state.txt`是658f407业务树在资料提交后的只读15项Ran状态；证明当前已应用，不单独用它证明初次空库迁移。初次执行顺序见报告。归档JSON已扫描password/TOTP/recovery/token/signed URL等禁止字段，未发现；图片仅本机合成数据。
