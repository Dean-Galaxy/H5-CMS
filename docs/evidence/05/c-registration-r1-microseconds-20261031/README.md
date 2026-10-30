# R1微秒到期边界修复证据

2026-10-31。源码`9dec77f0f766077c69cd62007ca94cce1989f7a5`；正常合入00候选`f9d21fbccc54e7c3796e83b9824c8d0edc91174f`的合并点`c5e32d2dd0a400f12e4afe2bb14a9ed343c9aa05`。只用05自己的已标记`_r1a_clean_test`真PostgreSQL测试库，不查询或修复06现场。

| 文件 | 结果/含义 |
|---|---|
| old-final.txt | 新测试在旧产品文件上7失败/3通过、97断言；旧文件与f9候选逐字一致。日志中的测试UUID统一替换为synthetic-id |
| new-tests.txt | 相同测试文件、只改4个SQL时间绑定后10项196断言通过；测试SHA与红轮相同 |
| current-regression.txt | 正常合入f9后Acquisition＋RuntimeEnvironmentGuardTest组合182项3493断言，15.80秒；包含上述10项，不重复累加 |
| audit.json | R1范围SQL审计：4个expires_at SQL边界均用已有Clock::stamp；PHP Carbon精确比较及旧v1/C逻辑保持 |
| contracts.txt / pint.txt / php-lint.txt | 1.0.5及59公共例、4文件格式和PHP语法通过；契约/依赖/迁移未改 |
| manifest.json | 基线分层、旧/新产品及相同测试SHA、去敏证据摘要与不主张事项 |

覆盖`.123456`和`.999999`的before/equal/after；proof到期即不可读，operation未到期确认原操作但返回当前deny，到期不再确认旧回执；成功的当前命令在事务中删除过期回执并按原规则新建，失败事务仍完整回滚。清理按limit=1分别限制operations/proofs，cascade随proof清自身选择；未来1微秒的对象保持到其真正到期。每轮批量清理前后全部非R1表完整行摘要一致，其中含有合成旧广告deny、付款收件/来源/发送记录，不伪称写入或修改Billing账务。

**这不是06原生命周期首个断言的恢复定位。** 00独立证实的问题由本轮独立真PG红绿复验，不宣称06生命周期、人工或部署通过，也不延长其绝对策略截止。命令、范围和交接见[修复交接](../../../handoffs/05-C注册R1微秒到期修复.md)。
