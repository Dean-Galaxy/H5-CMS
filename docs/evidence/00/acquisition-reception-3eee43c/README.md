# 00 对05 F07修复的独立复验证据

2026-10-07。05固定交付3eee43c8490bff46229e5f39a13fb19114e87877；00基于已接收9298b5031231b687c923f5849feebf93409be425建立隔离组合d60ccb054344087db4d62c32186246d2207475fa，应用/测试/runner与05最终实测5d1abeaa8ab6134b037f3e5ab67d1e7f9319f42c逐文件一致。05最终15份证据SHA均核对通过，但以下测试和进程记录由00另外执行，不以05报告代替。

- original-f07-probes.txt：00原样探针2项/3断言通过，14.19秒。脚本逐字等同[首轮探针](../acquisition-reception-555e9b0/AcquisitionDeliveryBoundaryProbeTest.php)，SHA-256 c33723cfab90e563cbcbd825ccbb13d20ca64d4be74f24c46f20fc87c18e1a2f；没有改预期。原555上的失败保留在00-13。
- full-tests.txt：精确组合默认全套279项/2905断言通过，136.14秒；H5_LOCAL_MODULES为空，包含40项Acquisition检查及两项真实目录消费者测试。未为计数重复跑专项。
- 两份test_*.json：来自本次00全套测试。Meta/TikTok分别在旧PHP进程准备阶段暂停，控制其租约到期，再由另一个真实PHP/PG连接接管；旧进程恢复后0发送且未改写新结果。两平台各在HTTP替身内SIGKILL六个子进程，父连接核实已提交attempts 1到6；原报文、event_id与首次时间不变，第七次无调用，I/O事务层级0。未声称真实平台接收或恰好一次传输。
- schema.txt：1.0.3结构和57正反样例通过。变动的DeliveryWorker及4个测试/辅助文件Pint通过；应用差异git空白检查通过。
- start.txt / migrations.txt：00自己的8300/PG5630实际启动，原开发库h5_979f222b25增量执行050001–050003；全套测试只用h5_979f222b25_test。迁移是原05新增的3项，F07没有修改已采用迁移或增加依赖，沿用05原同日依赖审计，不冒称新联网审计。
- runtime-enabled.json：仅此复核启动显式local启用acquisition，两个真实读取提供方、归因false/null、外发disabled。runtime-default.json验证没有开关时Acquisition仍not_ready；本次不改公共默认装载。
- http.json：真实HTTP健康200、未知观众地区目录403、推广落地503 DEPENDENCY_NOT_READY、匿名投流后台302至员工登录。没有普通运行假片单、付款或成功发送。

测试串行使用00测试库；开发启动使用另一独立开发库，未清空或复制其他任务数据/配置。人员/媒体/付款为testing样例，所有广告HTTP均被测试替身拦截。00查看了05提交的后台截图，未把它记成00重新完成浏览器MFA操作；真实空名单/片单的浏览器自验归05证据。

文本归档只去掉颜色和行尾空白；原始日志保留在00工作区.runtime。D05、网站隐私/落地衔接、官方支付与广告平台联调仍未完成。
