# 00 员工后台公共组件验证

源码提交：`b827aefeec9c2bd16c16de69a112130d28c78e1d`。业务契约1.0.4，内部UI接口admin-ui-v1。检查日期2026-10-21。`source-manifest.json`记录12个应用/测试文件的SHA-256；保存证据时逐个对照该提交，内容一致。

| 证据 | 结果和范围 |
|---|---|
| targeted-tests.txt | AdminUiTest、AcceptedAudienceTest、AuthorizationTest：15项、158断言通过；主工作区独立测试库 |
| browser.json | 真实Chrome隔离浏览器运行公共组件夹具：17项通过，无脚本异常；不代表模块业务页面通过 |
| mobile-dialog.png | 375×667长弹窗，操作按钮在可见区域，截图仅公共夹具 |
| contracts.txt | OpenAPI 1.0.4结构及57个有效/无效样例通过 |
| dependency-audit.json | 当前锁定依赖审计advisories、abandoned、filter均为空；没有安装或更新依赖 |
| migrations.txt | 主工作区12项既有迁移均Ran；本次无迁移变更 |
| style.txt | 本轮4个PHP文件Pint检查通过 |

两份JavaScript通过Node语法检查，Git差异空白检查通过。主工作区服务实际启动正常；临时夹具HTTP服务测试后已停止。

## 独立复验

使用仓库`scripts/dev.py`初始化和启动自己的工作区；禁止复制其他任务的环境、数据库或密钥。目标测试由`scripts/ops/run_acceptance.py`传入上述三个测试路径执行。

公共浏览器夹具由`h5-platform/tests/Feature/Contracts/Support/admin-ui-fixture.php`生成到当前工作区`.runtime/admin-ui-review`，在回环地址独立端口临时提供静态服务。已有Playwright通过`H5_PLAYWRIGHT_MODULE`指定，Chrome可用`H5_CHROME_BIN`指定；在仓库根运行`h5-platform/tests/Feature/Contracts/Support/admin-ui.browser.cjs`并传入该回环URL，写入浏览器结果和截图。该工具不建立业务账号、不注册正式应用路由、不写业务数据库。不要为此复制其他工作区配置或对外开放服务。

模块页面、媒体替换、发布规则、员工密码和账务/投流UI仍由原模块交付后，交06在同一完整组合SHA复验。本证据不证明官方支付、真实邮件、地区确认、广告外发或对外上线可用。
