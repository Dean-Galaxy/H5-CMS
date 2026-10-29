# 05 站内分析链接筛选必要补齐

2026-10-29。源码87b97c6cc3a3fa90c529fb1072b5182aedc91074；在前05交付2b11037后按00/08有限复看指令补修。共同65a2ced是祖先，业务1.0.5附加analytics-v1，不改变公共契约。

原分析下拉复用了“推广链接”当前页50条，旧链接不可达。现为独立、授权范围内的名称搜索+50条分页，按名称/ID稳定排序；最多额外保留一个仍有权的已选项。选项只有ID/名称，无用户资料。LinkFilter使用AssetAccess::query('link')，名称查询前先限自己或当前分配；选择值每次重新AssetAccess校验，撤权显示通用占位并拒绝报告，不清选择、不回退全站。搜索应用值与输入草稿分开，过长失败保留原查询/选择；%/_/!按字面处理，不是通配放宽。沿用公共表单组件，无新壳/公共CSS。

| 验证 | 结果 | 证据 |
|---|---|---|
| >50、末条搜索、独立分页、已选保留/搜索错误、同关键词越权、撤销、空范围与字面字符 | 新增4项通过 | search-and-desk-tests.txt |
| 原后台操作/权限/导航/未保存编辑 | 11项通过；与新增合计15项179断言 | search-and-desk-tests.txt |
| 相关分析界面/自有和分配/撤销/0分母 | 4项27断言通过 | related-analysis-tests.txt |
| 真实Chrome员工密码+MFA，61个合成链接，桌面/手机/短屏 | 8项、0外联、0JS错误 | browser-result.json及5图 |

未运行或冒称本增量重新通过无关完整系统套件；此前79项/14项组合/20项浏览器证据保留为其候选结果。00/06/08对此增量只复验链接筛选、相关UI和对象授权。

Chrome实际复演：旧目标不在主链接首50条；分析的独立分页能到目标，名称搜索找到末条；选择查询后换关键字仍保留已选；390×844和375×500重新输入/搜索/查询成功，实际视口截图，无全页溢出。输入/按钮可滚动到达，不用点击脚本绕过遮挡。权限及撤销由真实对象权限服务和Livewire测试验证；Chrome使用Owner本地测试账户，不将Owner截图当投流角色的授权证明。

只在05自有8205/5605，通过既有Acquisition服务创建61条测试链接并保留，未改Content/Billing事实。无新迁移、依赖、身份/价格/会员/付款规则，外部广告保持disabled，实际06/周四站未操作。私密员工账号、MFA、运行数据均不入Git。

复演脚本：

```text
php h5-platform/tests/Feature/Acquisition/Support/analytics-link-search-fixture.php
H5_PLAYWRIGHT_MODULE=<本机Playwright模块路径> node h5-platform/tests/Feature/Acquisition/Support/analytics-link-search-smoke.cjs
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Acquisition/AnalyticsLinkFilterTest.php tests/Feature/Acquisition/DeskTest.php
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Acquisition/AnalyticsTest.php --filter='analytics_tab_renders|report_authorized_scope|assigned_operator|own_links'
```

脚本限定自身local+外发关闭；PHP使用本机8.4。源码及文档固定交00串行接收，GitHub留存另由00上传并回读，未把本机提交称已备份或广告可投放。
