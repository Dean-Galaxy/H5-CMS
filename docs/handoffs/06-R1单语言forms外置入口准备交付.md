# 06 R1单语言forms外置入口准备交付

2026-10-31。**仅小型候选外源码准备，未执行Chrome、注册或取图。** 新入口`scripts/ops/observers/r1_forms_v1.cjs`，来源为冻结`scripts/ops/r1_browser.cjs`的原forms()；移除重复初始layout/旧固定截图名，按下表原业务断言逐段保留，不造后续通用框架或自动恢复。

应用A仍`1263bdcb3576986a74a0cf59a7c7ea61072b9062`、产品BASE81961c0；原观察器O=`0c861042186f424108ea06f0611aa3a61bb1a80a`分支保留。当前在同一纯源码工作区从O建`codex/06-r1-forms-observer`；新独立观察器提交F的完整SHA由提交后回执固定，不部署F到实际运行区。

| 原forms检查 | 单语言入口保留方式 |
|---|---|
| 初始未勾选/中英语义 | en与zh_CN显式分次；必要条款及两可选均未预勾，同站条款/隐私页200且可读；v3 unknown/未运行/no-store |
| 必要条款两项真实422 | 同固定材料，以真实JSON boolean false及错误terms_version发两次；每次actor-status确认未建号/无收据 |
| 一次真实建号/必要收据 | 同页真实HTML表单仅一次接受必要条款，两可选保持不选；明确created回执、1次必要提交、必要收据版本一致、口令清空、两项not_requested、目的PATCH为0 |
| 自己签名邮件 | 仅本区该材料自己的preview EML/HTML提取唯一同站验证链接，真实访问后同账号me及DB verified=true；不是外部邮件投递通过 |
| 原site deny保持 | 本浏览器真实v2 deny→注册→验证→退出→原账号真实登录；deny/off与revision保持，两新目的仍unknown/effective=false、无目的PATCH |
| 下游状态 | 成功后仅首次写0600 browser-state-en.json或browser-state-zh.json，供原management读取；已有状态/回执不覆盖 |

不重复新capture已单验的初始layout，也不把71帧/08有限复看或892算成此次forms通过。仅注册后与失败诊断有独立唯一目录的native fullPage:false图，记录A/F/源码/manifest、scroll/viewport/页面高、PNG摘要和input遮罩；不用旧固定名、不删旧图。

## 调用与停止边界

```sh
node scripts/ops/observers/r1_forms_v1.cjs en '<00已接收F的40位SHA>'
node scripts/ops/observers/r1_forms_v1.cjs zh_CN '<00已接收F的40位SHA>'
```

一次仅一个语言，固定复用auto-main-en/auto-main-zh：如无材料只能由本区原actor工具生成此固定材料；使用前必须实际actor-status尚未建号/无收据，且无旧forms attempt或成功browser-state。已有部分成功只记当前状态停止，不换label、不重注册、不登录恢复、不复用失败会话来自动补后续。

与fault/capture共用原active-case串行锁、同IP注册ledger与至少12.5秒节奏。真实注册请求总3次＝两项422＋一次必要创建；真实429写Retry-After并停止，不清缓存/换scope/IP/账号补额。失败仅保存阶段、响应统计、当前actor状态和独立native图，不自动恢复；窗口持有人06随后按既有checkpoint/备份/正常停机安排收尾。

独立F同时绑定入口自身及原core/fault/capture共四个执行文件字节与源码Git SHA；实际A/manifest/PID/原root和回环8412/manual/原FROM-UNTIL门槛复用原preflight。只从shared runner导出原私密读写/control，并将原注册pacing等价提为复用函数，原fault节奏与场景不变；不扩角色、政策、业务或其他测试组。原10个运行工具/产品/BASE不动，原11fault仍未执行。

最小离线覆盖共17项（原12＋语言/固定label、部分成功拒绝、JSON负例语义、429停/保Retry-After、自己邮件唯一URL选择5项）；4执行JS语法/diff与精确工具差异检查按提交后的F记录。不启浏览器/数据库，不在源码区建.env/setup或复制运行资料。

00须先复核/扫描/独立标签回读F，且08初始图复看接收后另给实际执行消息。当前实际A停机、数据/原期限保持，用户和07无动作；成功注册不得重复，新人工仍0/3。
