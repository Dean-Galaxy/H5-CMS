# 05 direct-test-1 实施交付

2026-11-01。05模块本轮实现和本地专项完成，待00串行接收与04组合；未部署staging、录入真实凭据或请求平台。统一起点 `f4258a01deb39151cd032e2733492e156123e7be`，源码 `8cd721b6bf2ae092d04d733748159be8c168fcd1`，分支 `codex/05-direct-test`。业务契约1.0.5，增量 [direct-test-1](../contracts/acquisition-direct-test-v1.md)。原起点标签 `h5-audience-v1`完整提交仍为`501dc2584b8973239669e346fd12dcca19e6ce47`，本轮不回退旧起点；R2-A UI分支`codex/05-r2a-report-ui`固定`3fb869f062c61125f8e05947577d79e163faceea`保留且未混入。

## 实际完成

- 绑定公共`CheckoutAttribution`到`CheckoutContextStore`。仅接受04服务端购买draft的`/membership`、UTC页面时间（不未来、不超30分钟）与当前同站HTTPS POST；Origin必须一致，Fetch-Metadata不符、Forwarded或转发IP/Host/Proto头、预加载、非公网直连IP或无效UA均不捕获。重新核当前广告同意、可信来源六字段、像素历史版本及现有拒绝标记；异常/存储故障返回null，savepoint不污染外层购买事务。04负责实际GET证据和首次attempt固定，本模块不替代其draft校验。
- 新迁移`2026_11_01_050009_create_acquisition_checkout_contexts.php`仅新增本模块加密上下文表；共28份应用迁移。UUID绑定source/privacy/pixel/version/platform，无viewer/订单/会员关联列。交易页、当前直连IP/UA和对应平台既有获准click/cookie信息加密存储；不改source落地URL、期限或旧六字段JSON。
- 本期上下文有效截止取“捕获后24小时、原source截止、source捕获后90天”最早值；现`acquisition:analytics-prune --limit=1000`有界清理过期新表记录，物理清理由现调度执行。旧source/账务/收件/待发记录与既有冻结载荷不清理、不改写；过期/清理后该投递明确阻断，不重新捕获或补同意。
- 受控staging boot、非本地凭据命令及每次发送重新调用00真实Guard。仅广告入口可用：`advertising_test_only=true`、`analytics_available=false`；分析true输入不能存为grant，不创建subject、行为、账户映射或R1选择，退出清理也不调用C管线。旧local/testing路径与拒绝/撤回桥保留。
- HTTP发送从付款固定的`checkout_context_id`读取购买证据，验证source、privacy、像素版本、平台、外部编号及用途绑定。缺失/失配/过期明确阻断；受控通道只允许测试付款＋测试资产＋非模拟来源，暂拒续费。旧本地续费语义保留。
- 两家适配器新载荷只保留Purchase、事实结算时间、稳定event_id、USD实付金额、真实购买页和各自获准匹配字段；Meta新购为website。`transaction_kind/discount_applied`仅留本站事实，不再外发。原冻结载荷不符合当前最小形状或可信值时阻断，绝不原地迁移；沿用现租约、次数预留、幂等、失败和重试。HTTP适配器自身也拒绝默认关闭/未核准配置。
- 按00补派仅移除`external_enabled/platform_reviewed`两项`(bool)`强转，合法env true/false照常解析，junk/0/1等原值交Guard拒绝；未顺改C/R1配置。凭据复用已接收私密文件和隐藏TTY录入，不扩文件框架。
- 后台复用原有汇总、发送状态与重试入口，只新增安全原因文案；无新监控、归因、成本或脚本系统。公共层、04、03、根配置、历史迁移、依赖锁、真实.env及旧站均未修改。

## 实测与复用

本工作区自有标记测试库`h5_d2a32071a6_r1a_clean_test`、5605；仅合成数据和HTTP假件，`Http::preventStrayRequests()`。无新Web/浏览器/队列/调度窗口，没有连接R1/C2/周四库或重seed旧环境。

| 执行 | 实际结果 |
|---|---|
| 新增端口/受控模式、Delivery、Boundary、两个独立PHP进程、ClickMatching、TrackingHttp组合 | 39项706断言通过，4.45秒 |
| 随后追加模拟来源断言＋直接受影响原本地TTY命令 | 2项18断言通过；含前组重叠用例，不累加计数 |
| 00补派的原始env解析→真实Guard专项 | 1项19断言通过，含合法和非法六组配置；子进程只加载配置，不联网 |
| 随后ad-only清理不进入C＋微秒截止prune窄补验 | 2项28断言通过；含前组重叠用例，不累加计数 |
| 最终静态检查 | 21份PHP语法、Pint、Git空白检查通过 |

证据：[本轮目录](../evidence/05/direct-test-20261101/manifest.json)。首轮6项失败为夹具时间截秒、错误链接字段、缺账务Reader时误启分析及JSONB键顺序断言，已修；必要重验结果如上。迁移新建由RefreshDatabase覆盖，专项实际先移除新表模拟旧库/捕获存储故障，再up并逐行保持source/receipt/delivery/pixel及旧六字段JSON。两个真实PHP进程只证明本地假发送的租约接管、崩溃预算与固定载荷，非平台请求。测试子进程夹具新增购买上下文，并修正其原来仍指向旧`.runtime`路径的守卫，使其仅接受当前专属临时目录。

未变的R1全套、人工6/6、旧支付、整产品、私密文件安全及R2-A证据复用，没有重跑或新增人工通过结论。未改UI布局，只增状态原因，未新取图或宣称新浏览器验收。00的公共17项415断言属于上游接收，本模块没有重复运行。

## 怎样使用与接收

1. 00串行接收本源码，再与04实际draft/隐私接线组合；只对新独立工程环境执行新增迁移。本分支保持f425起点，未中途并04或改主目录。
2. 获00放行的新环境中，员工仍进入`/admin/acquisition-desk`：在“像素资产”登记各自获授权的专用测试资产，在“推广链接”绑定已发布剧目和对应平台像素；“查看本站汇总”看状态/安全原因，“重试待处理发送”沿用原次数与时限。没有打开任何当前人工入口。
3. 私密配置由工程在受信主机TTY执行`php artisan acquisition:credentials:set <pixel UUID> <当前版本>`。local/testing原限制保持；非本地必须通过00受控staging准入且资产为test。Token和test_event_code隐藏输入，配置保存不发送、不启开关；不要把生产Pixel改标test。日常人员无需编辑配置或运行命令。
4. 新购买只经04正常页面→首次确认→03核实结算；缺广告来源或capture返回null仍可合法购买，广告发送另行阻断。不得用前端“付款成功”触发发送，不能给旧订单后补context。
5. GitHub扫描/标签/上传回读、部署、真实开关与实际官方测试由00协调；本轮仅提交本地源码和证据，未声称已备份远程。

| 平台 | 本轮功能 | 本地通过 | 外部联调 |
|---|---|---|---|
| Meta | 已实现，待00组合接收 | HTTP假件、最小载荷、去重/重试/隔离通过 | 未执行；无真实API接受/Test Events/归因证据 |
| TikTok | 已实现，待00组合接收 | HTTP假件、最小载荷、去重/重试/隔离通过 | 未执行；无真实API接受/Test Events/归因证据 |

本轮复核[Meta官方Event结构](https://raw.githubusercontent.com/facebook/facebook-php-business-sdk/main/src/FacebookAds/Object/ServerSide/Event.php)与[CustomData](https://raw.githubusercontent.com/facebook/facebook-php-business-sdk/main/src/FacebookAds/Object/ServerSide/CustomData.php)，以及[TikTok官方Purchase及currency/value说明](https://ads.tiktok.com/resources/help/article/standard-events-parameters?lang=en)。TikTok API门户仍无可读正文，本轮不把门户访问当完整账户要求核验；沿用已接收发送协议，不增加SDK或接口。HTTP假件、本机模拟、官方API接受、平台Test Events确认和广告归因是不同结果，不合并平台主张为公司收入，不称ROAS/投放跑通。

下一次同事最小配合仍集中交07：各平台本项目专用测试资产ID及授权/管理员权限、账号持有人可生成的专用token与test_event_code（仅私密入口录入）、00/06确认的域名/直连HTTPS入口、00与账号持有人落实用途/隐私/平台适用要求，并在Events Manager现场确认测试事件。现在不补问卷、不采购第三方、不扩成本API或改正式续费策略。
