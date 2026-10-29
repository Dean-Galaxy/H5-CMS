# 05 报表查询完整性与容量修复

2026-10-29。源码6effb469002466d509c6dbdd8a7145167ac600ba，接在搜索交付0e55b7后；按00接收方案修复实际漏数，不添加外部BI或新业务规则。

原实现先取前10001个raw事件，再截前10000重建period和队列。即使主体已缩小，同主体在其他链接的大量watch_progress仍会挤掉目标；独立汇总、分集、财务都随raw截断，缩小筛选并不可靠。before.txt为初始10050进度复现的红例，最终测试增加无关主体10050条并精确断言结果。

| 验证 | 实际结果 | 证据 |
|---|---|---|
| 容量3项+既有Analytics16+搜索权限4+真实Providers3 | 26项265断言通过，本机3.67秒 | report-capacity-tests.txt |
| 受影响06真实付款/匹配组合 | 8项214断言通过 | affected-combined-tests.txt |
| 自身Chrome搜索/报告（含390/375×500） | 8项、0外联、0JS错误 | browser-result.json及5图 |
| 新增050005索引迁移 | 05自身库Ran，旧050004未改 | migration.txt、migration-status.txt |

实现：完整筛选period的独立统计/有效观看/错误/分集均SQL聚合；完成匹配按同主体、环境、分集和play_instance在开始后7天EXISTS。财务distinct payment IDs按200 keyset Reader分块累加，不拉全量事实，不读Billing表。漏斗仅保留最多1万入口主体、按200入口分批LATERAL寻找首个有序阶段，每阶段至多一条，排除原始心跳；UTC7天用固定168小时，原排序ordered_at+sequence及非续费限制保持。字段兼容，新增入口总数/处理人数用于清楚告知真正触顶，仅漏斗限流。

压力夹具与精确结果：

- 已同意主体A在其他授权链接10050进度，独立无关主体C同样10050；A及B有目标链接完整转换。目标筛选两人各阶段完整、末步100%，期间有效观看90秒；毛收600、当前成功累计退款100、净收500。两类干扰均不挤掉目标。
- B付款后播放/完成跨UTC日边界：入口队列7天内仍匹配，独立计数/观看秒只进对应期间；设备/测试真实过滤不回退到全站。分集两播放两完成100%、首帧均值750ms、卡顿累计300ms。
- 真10001入口仅处理前10000漏斗入口，漏斗率为空且界面明确标注；独立访问10001、观看60秒、分集完成率100%、财务known继续完整，不假装未知或0。
- 401笔付款另加一个重复payment_id，Reader实际批次200/200/1，毛收120300、退款40100、净收80200，付款仍401笔。

数据只在05独立testing PostgreSQL批量构造本模块保留事件及明确Reader替身。它不是普通运行假付款、真实付款验证、平台归因或生产压测承诺。没有一次get百万raw；PHP最多1万入口和每批200个阶段结果，独立指标只取聚合结果。返回剧集维度随实际汇总组数，不设raw行截断。新索引对生产数据创建的耗时/锁窗口仍由00/06部署前评估，本机4ms不外推。

另修正搜索测试夹具稳定性：主列表按ID倒序，旧随机v4目标可能随机落在前50；测试现使用确定旧ID、其余v7。只改夹具，不改产品排序。

复演：05自身工作区运行以下定向套件；06组合沿用明确启用acquisition、external-off的测试环境，不能连接真实06/周四数据库。

```text
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Acquisition/AnalyticsCapacityTest.php tests/Feature/Acquisition/AnalyticsTest.php tests/Feature/Acquisition/AnalyticsLinkFilterTest.php tests/Feature/Acquisition/AnalyticsProvidersTest.php
H5_PLAYWRIGHT_MODULE=<本机Playwright模块路径> node h5-platform/tests/Feature/Acquisition/Support/analytics-link-search-smoke.cjs
```

索引迁移050005已仅在本工作区执行。截图是本工作区合成链接/测试媒体，不含真实用户或凭据。站点继续保留供08只读复看，外发disabled、实际支付站和周四站未动。固定交00后仅对受影响查询/权限/UI验收；最终系统及GitHub备份由00/06统一，未把本地提交称已远程备份。
