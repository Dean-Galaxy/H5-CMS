# F07 修复与独立进程验证

2026-10-07。修复源码 `724f1522d0064aa68b3ec170a31fdd2ef1558b08`，基于退回交付 `555e9b069bd542240d787b4d5cd8dc37ef70ba45`。此处是合并新读取提供方之前的独立证据，后续组合在交接单另记。

00复核单固定提交 `44748ed31c23a859412ff4304c041dadc5051f5d`，路径 `docs/handoffs/00-13-05独立阶段复核.md`。使用的[原始探针](original-probe.php.txt)已与该提交逐字核对，没有修改预期值。

## 原问题与修复

| 边界 | 原行为 | 修复后 |
|---|---|---|
| 准备时租约过期/被接管 | 旧token写入影响0行，仍调用发送器 | 重新锁定当前行核对state/token/有效期；失效退出，不调用、不改新持有者 |
| 首次时间、报文、次数 | 分散写入，attempts在网络返回后递增 | 同一短事务冻结报文、保留首次时间并预占attempts+1；提交成功才调用传输 |
| 网络I/O中进程退出 | 本次调用可能漏计，恢复后超过6次 | 预占不退还；提交后尚未实际调用就退出也保守消耗预算 |
| 结果迟到 | 只校验token，不检查租约期限 | 结果短事务重新核验有效持有权；不改接管者，也不补写/回退次数 |
| 准备期间隐私变化 | 只在准备前检查 | 预占边界重读来源/像素与门禁，撤回后不预占、不发送 |

预占时拒绝已用尽预算、未知首次时间或超过24小时的历史尝试；沿用首次时间、event_id及原加密报文。人工重排不能清零。只为有效持有者续期，并把租约截止限制在首次尝试后24小时内；传输前再次检查持有权。网络不在该短事务中，结果保存不修改已预占次数。

没有新增或修改迁移、公共契约、01–04或来源入口。保留两平台、付款双键去重、隐私/环境拒绝、来源为空不阻塞会员。此修复不宣称外部平台严格一次投递；未知结果仍靠稳定ID、原报文、期限及预算保守处理，平台确认待联调。

## 实际证据

| 验证 | 结果 |
|---|---|
| 原样00探针 | [修复前](before.txt)2失败/3断言：调用1次而预期0，第6次进入HTTP时读到5而预期6；[修复后](after-original-probes.txt)2通过/3断言 |
| 新边界与进程测试 | [boundary-process-regression.txt](boundary-process-regression.txt)：6项/172断言，含仅过期、接管、迟到响应、首次时间、预占及隐私撤回 |
| 真实进程接管 | [接管记录](test_real_process_takeover_leaves_resumed_old_preparer_without_transport_or_writes.json)：两平台均运行不同PHP进程/PG连接。父测试控制旧租约到期，新进程实际claim并完成；旧进程恢复后0次调用，整行与新进程结果一致 |
| 真实进程中断 | [中断记录](test_six_sigkills_inside_http_do_not_lose_reservations_or_allow_a_seventh_call.json)：每平台六次在HTTP替身内暂停后SIGKILL；父连接提前读到已提交1–6，首次时间/原报文/event_id不变；第七个恢复进程0次调用，RETRY_EXHAUSTED |
| 无长事务覆盖网络 | 每次I/O的transaction_level均为0；父连接在子进程暂停时能读到已提交预算 |
| 模块全套 | [module-tests.txt](module-tests.txt)：38项/425断言；保留双平台、失败重试、去重、环境隔离和03测试引擎/Outbox消费失败不撤会员 |
| 默认全套 | [full-regression.txt](full-regression.txt)：252项/2394断言；H5_LOCAL_MODULES显式清空，没有放松公共默认装载断言 |
| 运行及迁移 | [runtime-status.json](runtime-status.json)五项true；[runtime-enabled.json](runtime-enabled.json)本机local显式Acquisition、外发disabled、归因false/null；[迁移](migrations.txt)无新增/未执行项；[实际空队列调度](dispatch-empty.txt)无外发 |
| Schema和依赖 | [schema.txt](schema.txt)：1.0.3与57样例通过；锁文件/依赖未变，沿用原交付同日审计，不冒作新一次联网审计 |

进程测试仅用专属测试库和HTTP替身；辅助脚本验证testing、H5_TEST_DATABASE、_test后缀及本工作区临时目录，拒绝普通环境。SIGKILL只针对本次创建并持有句柄的子进程。

首轮辅助测试用了Symfony不存在的isSignaled()方法，导致中断用例中途报错，[失败日志](boundary-process-tests.txt)保留。核对安装源码后改为hasBeenSignaled()，保留实际信号9断言；没有改业务代码规避检查。标记文件写完再重命名，避免读取半份JSON。

## 复验

在本工作区运行：

```sh
python3 h5-platform/tests/Feature/Acquisition/Support/run.py tests/Feature/Acquisition/DeliveryBoundaryTest.php tests/Feature/Acquisition/DeliveryProcessTest.php
python3 h5-platform/tests/Feature/Acquisition/Support/run.py
H5_LOCAL_MODULES= python3 scripts/dev.py test
```

当前为05自验重交，F07关闭由00独立复验决定；D05、04插槽及广告外发未放行。
