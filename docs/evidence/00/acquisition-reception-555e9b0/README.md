# 00 对05独立阶段及只读提供方的复核

2026-10-07。05固定交付555e9b069bd542240d787b4d5cd8dc37ef70ba45（应用72c1235）；01提供方f1363f62dc39cb57acea4f41f7e6ecc969c97a1b；02提供方5ad42071b75151ba155ca6cb809b909cba5d644b。三个交付顺序合并到00隔离候选cabbc76f6b8e8a368fcc4b6f4b06864a238ed966；新增00组合测试固定于2bc3570807b0aca42a412d927263bce52827d44d。它们尚未因此全部进入main。

- 环境：00自己的KESU复核工作区，PostgreSQL5630、测试库h5_979f222b25_test；不使用子任务的.env、数据库或素材。
- combined-tests.txt：现有全套271项/2657断言通过，103.32秒；默认H5_LOCAL_MODULES清空，各Acquisition测试显式注册模块。候选提交时间1791365590，测试日志创建1791365597，实际执行发生在三个合并完成之后。
- schema.txt：共同契约1.0.3、57个正反样例通过。使用00现有contracts校验环境；首次误选另一个不含jsonschema的旧venv，仅为工具环境问题，未变更Schema。
- real-directory-integration.txt：新增00真实Identity/Content/Acquisition服务组合1项/27断言通过，10.43秒。覆盖真实员工列表、US库存与ZZ观众地区隔离、创建像素/链接、多人共享、分别授权、撤权、停用员工复核及下架目标停止链接。媒体字节与人员为明确testing样例，不是实际视频浏览器验收。
- 新组合测试初次把Identity受保护active字段用普通update设置，样例未停用导致1项失败；改用测试专用forceFill并增加数据库状态断言后通过。没有为此改业务代码。
- f07-probe-failing.txt：两个新增故障边界探针均失败，3断言，5.91秒；旧进程失去有效处理权后仍调用发送器1次；第6次HTTP替身开始时数据库尝试次数仍为5。前者明确推进61秒并模拟新进程接管；后者在网络边界观察持久状态，未真实杀进程。二者都没有真实广告HTTP。
- AcquisitionDeliveryBoundaryProbeTest.php是隔离复现脚本，放在证据目录不会被正常全套自动执行。05已收到修复要求，修复后用同一探针复验，并补自己的进程中断/竞争测试。AcquisitionDirectoryIntegrationTest.php是总控组合测试的固定副本。
- 已核对05的19份证据文件SHA256与manifest一致，文件归属及公共文件无修改；它们仍按05自验记录，不改写成00执行的浏览器或官方证据。

发送实现的官方资料只作技术核对：TikTok明确同event/event_id的Events API去重窗口为48小时（https://ads.tiktok.com/resources/help/article/event-deduplication?lang=en）；官方模板 https://github.com/tiktok/gtm-template-eapi/blob/main/template.tpl 与Meta官方SDK https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/Object/ServerSide/EventRequest.php 。本次F07依据实际代码和隔离复现，不以平台去重代替本地任务持有权/预算约束，也不声称平台已收事件。

05阶段接收暂缓，目录提供方可单独进入后续接收。归因false/null、/r未就绪和外部发送关闭保持；D05、staging、Stripe及两广告平台官方验收仍未通过。
