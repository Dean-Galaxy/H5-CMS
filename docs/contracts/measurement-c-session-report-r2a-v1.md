# 基础C会话只读报表 c-session-report-r2a-1

2026-10-31，00接收05候选75382d5后冻结本隔离工程起点。业务1.0.5、analytics-v1、c-core及R1不变；不宣称完整R1/真实资格已通过。只读报表，不新增采集/身份边/订单映射/UTM/广告、表或留存，不激活R1旧allow。

## 范围和口径

| 项目 | 冻结规则 |
|---|---|
| 来源/权限 | 仅05自有C events/sessions/subjects，必要已保存R1可信结果单列。仅local/testing且Acquisition就绪、既有AcquisitionManage与Role::Owner同时满足可读合成is_test=true；服务端复核，投流或其他员工无C范围不能回退全站。查看不依赖启动采集/当前策略仍有效，不调用start/end/prune/偏好写。鉴权/依赖失败明确拒绝，不回空报表。 |
| 主链 | 同一measurement_session_id内，以sequence取E首次page_view→D其后同目标drama_view→P其后同目标play_started。目标固定该会话首个有效drama_view的drama_id，筛选/换剧不重定向；目标首次D早于E不倒补，后续同剧D才可成阶。E应先从保留前序确定，再判E.received_at入窗，不能先截事件后以刷新改入口。 |
| 去重/分支 | 每阶每会话一次；乱序后阶不补前缀，play_requested/预载/seek/心跳不作P，不跨会话/主体/账号/设备拼接。直接进入若有E/D/P可入链且入口类型另列；无E/缺D播放/无前序结果另列，不凑主链。sequence为服务端入库序，不代表用户操作或提交先后，缺号不等于丢包。 |
| 期间/快照 | E.received_at为UTC半开[from,until)，默认最近7天、最长连续7天、until≤as_of。接收合法UTC ISO8601、拒绝无效/未来或过长范围；入窗会话尾部可超过until直到as_of/已知边界，不跨午夜硬断。一次一致性READ ONLY快照（单语句或repeatable-read只读事务），as_of在此快照取一次；不开放任意历史as_of重建。 |
| 分组/前序 | 按主体is_test/政策/notice及事件schema分组，主链事件须匹配主体。先只合成样本；版本不合/关联缺失不静默混入。会话创建早于as_of−29天或缺保留前序记覆盖未知，不承诺30天前重现，不延留存。 |
| 结束/缺证 | 最早的已存session/subject ended_at、last_seen+30min、session/subject expires_at作已知界，微秒精度不截断。未到界称“观察中”，非仍在线/仍获准证明；ended仅称“已知截断，原因未证”；到界未见后阶为“窗口结束未观察到”。缺end_reason/历史政策截止/丢包证明不猜原因。不得查关闭后的必要媒体日志补分子、前史或全站分母。 |
| 比例 | D/E、P/D只取同组有序前阶分母，0分母显示—；“已观测比例”并列观察中/截断/覆盖未知，不称流失/最终转化率。不混旧v1同意样本，不查01/03表，不用全站付款数除入口。 |
| 可信结果卡 | R1 registration_succeeded/failed可仅按其received_at入同一筛选窗口、as_of前的已记录会话去重数独立列，带其独立用途/notice版本，明确与主链入口队列不同，不相除、不接注册漏斗；无记录显示“无证据”而非确无注册。未生产的前端注册后阶/验证成功与付款显示未接入/不适用，不造0%。 |
| 有界查询 | 最多处理10,000个已准入入口会话；超限明确limited/coverage，显示实际处理范围并提示缩日期，不能将抽样/部分分子冒充全量比例；不暴露raw事件、session/proof/账号/完整URL或导出。不得在实现中无界加载所有原始事件；可为limit预查计数，不靠读取引起写入。 |

## 分工、样例及接收

05先仅后端CoreSessionReport与针对性测试；返回内部PHP汇总结构及有限覆盖状态，公开字段不带测量标识。既有旧AnalyticsReport/报表/资产权限不改；不新增公共DTO/权限枚举/路由。UI等08基于admin-ui-v1给最小入口/展示方案后由05接既有AcquisitionDesk，不自画壳或改共享Panel。

候选历史与[三组说明样例](../contract-requests/examples/05-r2a-session-report-cases.json)保留；解释为合成说明而非运行fixture。A有序/直接进入/半开上界，B重复乱序/首剧/0分母，C已知结束/不跨session或必要日志补数。实现中转为最少有辨别力测试，另核Owner/非Owner、无依赖非空、快照/只读、超限与分组；不复跑未变全产品/R1异常或真实站。

05仅在自己的隔离测试库自测，00一次必要独核；新UI/实际组合另按冻结交付接收，06不在关闭的R1/C2/周四库迁移、seed或续期。当前只有工程放行，无新人工入口、正式采集/身份增强/付款/广告授权。GitHub按本轮固定起点及后续开发交付扫描/回读；源代码备份不等于私密数据备份或独立恢复。
