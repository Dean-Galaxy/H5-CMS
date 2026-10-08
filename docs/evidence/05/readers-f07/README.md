# 05 F07修复与真实读取提供方的最终组合

2026-10-07。最终实测源码 `5d1abeaa8ab6134b037f3e5ab67d1e7f9319f42c`，契约1.0.3。证据文件由manifest逐项记录SHA-256；文本日志只去除颜色和行尾空白，未改测试结果。

## 固定组合

- F07修复源码：`724f1522d0064aa68b3ec170a31fdd2ef1558b08`。
- 先固定修复和独立进程证据：`09ecc1b8397cb6b83eb76ac6b43ad31e181a41ac`。
- 再正常合并00已接收起点：`h5-acquisition-readers-v1^{commit}` = `9298b5031231b687c923f5849feebf93409be425`；合并提交 `1a00cff70829759b0bd9fe3fb983bb485077615d`。
- 消费测试提交：`5d1abeaa8ab6134b037f3e5ab67d1e7f9319f42c`。最终交接包只追加05证据/文档，不改此应用和测试。

没有复制其他工作区环境、数据库或可写素材；没有重置既有工作。只消费00接受的StaffDirectory/ContentPromotableCatalog，未修改其实现、公共契约或共享测试。

## 本次检查

| 检查 | 证据与结论 |
|---|---|
| 默认全套 | [full-tests.txt](full-tests.txt)：279项/2905断言通过，41.09秒；运行时显式清空H5_LOCAL_MODULES |
| 05专项 | [module-tests.txt](module-tests.txt)：40项/501断言通过，10.09秒 |
| 真实提供方消费 | [consumer-tests.txt](consumer-tests.txt)：新增2项/76断言。两个绑定均断言为真实实现；两平台共享/对象范围/撤权、停用员工及变更角色保存重验、51员工游标跨页和已选回填、剧目下架后拒绝启用但允许停用均通过 |
| 真实消费者边界 | 使用Content测试基类的独立人员/媒体/发布样例；删除其地区替身后使用正常ServerRegionResolver，仍为ZZ。AttributionService=false、snapshot(null)=null、transport=disabled保持不变。未向正常开发库填假片单 |
| 00原样故障探针 | [original-f07-probes.txt](original-f07-probes.txt)：2项/3断言通过。原探针与00固定44748ed及当前已接收文件逐字一致，未修改预期 |
| 进程接管 | [进程记录](test_real_process_takeover_leaves_resumed_old_preparer_without_transport_or_writes.json)：两平台各由新PHP进程实际接管完成；旧准备进程恢复后无发送、无写入接管结果 |
| 进程中断 | [中断记录](test_six_sigkills_inside_http_do_not_lose_reservations_or_allow_a_seventh_call.json)：两平台各在HTTP替身内六次SIGKILL；独立连接观察到先提交的1–6次预算，首次时间/event_id/报文不变，第七次无调用；I/O事务层级0 |
| 真实运行 | [runtime-status.json](runtime-status.json)：本工作区五项检查true；[runtime.json](runtime.json)：真实目录绑定、匿名访问拒绝、ZZ地区、归因false/null、外发关闭；[空队列调度](dispatch-empty.txt)无外发 |
| 真实浏览器 | 重新完成本机专用负责人账号MFA登录，点击片单/像素分配/人员读取。正确显示当前开发库无可投放剧目、无投流员工；没有误报缺提供方。见[页面记录](browser-state.txt)与[截图](workbench-readers.png) |
| 迁移/契约 | [migrations.txt](migrations.txt)全部Ran，无新迁移；[schema.txt](schema.txt)：1.0.3与57样例通过 |
| 依赖/归属 | composer/npm清单和锁文件相对原555e9b0逐文件未改，沿用[同日原审计](../composer-audit.json)；不是新增联网审计。相对9298b503的增量均属于05路径，未改公共文件或01–04实现 |

同一测试数据库串行运行专项、原探针和全套，避免并发清库；进程记录取最终全套运行。HTTP替身不是网络接收，api_accepted测试状态不是平台确认；没有真实Meta/TikTok/Stripe请求。原探针失败及修复过程在[合并前证据](../f07/README.md)保留。

本机后台当前可演示登记/编辑/启停/本地模拟及读取空名单、空片单；有人员与发布样例的完整保存和撤权链路由上述隔离testing验证。没有把浏览器空数据检查宣称为真实业务素材的全链路验收。

00独立复核F07和合并仍待完成。D05/04插槽尚未放行，/r入口继续503；外部广告联调、平台确认及正式投放效果均未验证。
