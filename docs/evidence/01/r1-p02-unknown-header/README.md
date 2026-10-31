# R1-P02 未知结果标题窄修证据

2026-10-31。仅公开源码和完整真实翻译；起点e2dc870bfae74ce4d27fb5e4f9069cbe15559210，源码59ff02d3667171e1991a5b3a19a91ddb514a73a5，分支codex/01-r1-p02。没有访问实际站、私密目录/图/数据库/浏览器state/邮件/素材。

## 分清三种验证来源

- 新夹具registration-unconfirmed-labels.browser.cjs共32项：**16项**在Chrome执行从实际JS逐字提取的render和原validState函数，核两语×unknown/allow/deny/null×Allow/Deny意向；**8项**同样核saved/unchanged两语两choice完整原文；**8条**执行整份未改写的实际identity.js及全量真实翻译，所有HTTP拦截，模拟合法dirty流：unknown→allow/deny、allow→deny、deny→allow，每语4条。不能把前24项纯显示检查说成完整HTTP流程。
- 无可信旧state在render快照中以null提供，没有向真实页面/提供方塞入无效state、创造dirty流程或修改产品导出。意向与回读choice相同的快照也保持unconfirmed，不由值相同推导旧命令成功；没有引入unset业务枚举。
- 8条完整流模拟account首保存后坏JSON、advertising正常成功，account只显式重试。同操作号内存幂等，仅2个逻辑写入、PATCH3顺序account/ad/account；context GET1、注册POST1、me GET4、CSRF GET3。新标题没有额外GET/PATCH/注册，另一成功项不重发、expected_revision/operation及所有命令相同、effective=false。

## 结果文件

- baseline-red.json：产品修改前，同一夹具实际跑起点源码。16未知显示项和8完整流在历史限定断言失败；8正常标签检查通过。只说明公开源文案问题，非真实运行事件。
- unknown-header-green.json：上述32项修后全过；每项标kind来源，含实际脚本摘要及合成输出。无截图/实际数据结论。
- purpose-regression.json、recovery-regression.json、p01-regression.json：本次26/6/4全部重跑通过；26兼容夹具只接新增两键，其余合成文案边界保持，P01使用完整真实翻译。
- product-byte-check.json：脚本除render本地label构造外所有字节与起点相同，choice验证器原字节不变；翻译解析后严格只多两个新键，所有旧值相同；register view/CSS/锁字节保持。新旧产品摘要与5源码摘要另列。
- Pint、JS语法/两翻译PHP语法及diff检查通过。无后端、依赖、契约或迁移改动；没有运行PHP业务/全站、数据库、审计或共享结构校验，不混用旧结果。
- stopped-status.json：01运行五项false，全程未启动DB/Web/队列/调度。浏览器仅独立全mock上下文，结束关闭；端口18603仅被拦截来源，没有监听服务。

复跑新夹具使用本机Node，H5_PHP_BIN/H5_PLAYWRIGHT_TEST/H5_CHROME可指定已有工具；H5_IDENTITY_SCRIPT可用于旧JS导出文件对比。没有产品测试钩子/模拟开关或敏感持久存储。

候选只交00独立复核及08公开代码复核。未接入实际站、未重跑任何实际case、未上传GitHub/移动tag、未开入口，也不称08看过截图或R1整体/人工通过。
