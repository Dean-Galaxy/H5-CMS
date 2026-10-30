# C注册分用途 R1：隔离规则与HTTP起点

2026-10-31｜00汇合01 e773e26与05 b51e369后冻结本批规则/HTTP形状。标识`c-reg-r1-http`；业务1.0.5、analytics-v1、c-core-1/v2四接口不变。这里只批准隔离工程，不批准真实资格、广告外发或生产。

## 1. 批次与独立开发边界

R1采用“必要建号＋独立分用途选择保存＋拒绝保持”。**账户关联暂不运行**，只保存明确选择，页面不得说已开启；R2需新范围/告知及当前明确动作，不静默激活R1意向或补注册前轨迹。广告也不因注册/账户选项开启，D05不变。

00提供`h5.measurement_c_registration` / `H5_C_REGISTRATION_ENABLED=false`，严格bool，仅local/testing且C体验开启可显式true；它不是05提供方就绪或真实采集许可。当前任何已有站均不启用。存储选择另须05默认unknown/off、明确过期的合成策略（最长24h），不得继承已关闭游客站期限或把US观看区当居民资格。

| 并行子段 | 现在可以开发 | 仍须等公共接线 |
|---|---|---|
| 01-A | 必要示例条款/版本收据、正常/未知账号结果、表单主路径与退化、独立测试；默认关 | 01-B分项目的消费者与C身份结果口，等待00发布实际PHP端口，不能引用不存在类或05内部表 |
| 05-A | 本模块真实选择库、独立CAS/回执、本文HTTP、旧拒绝适配与模块测试；默认关 | 可信身份结果与结束职责先实现模块内服务/需求，不自行创建00共享类；00后续提供PHP facade/DTO并做适配 |
| 00/04/08/06 | 00提供开关/规则并接收；04共同管理与01表单协同、08沿既定设计复看、06新环境验收 | 这些不随本文冒称已开发或当前可手测；不能放开旧1eabb站注册 |

01-A/05-A的单独交付不算整个R1完成；00负责后续PHP端口及01-B串行接入，不以子段完成永久停止完整C。

## 2. 必要条款与建号（01事实）

| 项目 | 固定值/行为 |
|---|---|
| 本机版本 | `terms_version=local-c-terms-r1-v1`，`privacy_notice_version=local-c-privacy-r1-v1`；同站只读入口`/identity/test-terms`、`/identity/test-privacy` |
| 呈现 | 标题及显眼说明均写“本机注册测试示例，非正式服务协议/政策，不用于真实用户上线”；沿08必要勾选与两项可选分区，主按钮仍“创建账号” |
| 必要字段 | `terms_accepted=true`；JSON严格bool，HTML checkbox显式value=1并规范转换；两版本须等于服务器当前测试版本。可选false/缺省不作为建号错误 |
| 收据 | 01自有新增迁移：viewer引用、两版本、locale、服务端接受时间、is_test=true；不复制姓名/邮箱/口令、IP/UA或测量ID。与必要账号创建同模块事务原子提交 |
| 留存 | 仅本机测试收据，随该测试账号保存、删除账号时关联清理；不在本批声明生产法定期限或跨备份清除已验。正式上线另核准正式内容/期限，不能沿用测试文本 |
| 兼容 | 开关false时原注册API/页面不强加新必填字段；旧账号不补接受收据。已建号不因邮件/回程/可选保存失败回滚或重发注册 |

示例条款中英必须涵盖：这是隔离测试账号/合成内容；不得输入真实证件、银行卡或支付凭据；无真实收费；保护自己的本机测试口令；可选用途不影响注册/必要服务。本例不替代经营主体正式条款。

示例隐私中英必须涵盖：账号必要保存昵称、邮箱及口令哈希并用于登录/本地验证；邮件仅私密预览；本站基础会话按单独策略及可见告知、关闭独立；账户/广告分别可选，本批仅保存偏好、关联和外发未运行；不把默认处理称同意；可关闭/撤回，必要账务/权益不因退出改变。链接不能空白或跳其他供应商。

## 3. 选择、作用域与退出

两目的枚举为`account_link|advertising`；`choice=unknown|allow|deny`。保存命令仅allow/deny，省略项not_requested；前端两项分开调用，不以缺省checkbox撤回旧选择。

必要偏好proof独立于C主体，128bit随机、HttpOnly/Lax、按当前浏览器保存；只在明确变更时创建，GET不创建/延长。allow的最少owner引用仅用于当前真实账号权限校验，不带C主体/事件、不是分析账户边；scope对外为current_browser，allow仅对当前可信账号可见，换号/未登录不继承前账号allow/草稿。deny作为当前浏览器必要退出继续保持。

首次新目的unknown不预勾；已有同scope真实选择回读显示。新目的证据工程上限24个月，重复回读/幂等/未变化操作不续期；旧site deny保持原截止，不因新选择延长。操作回执上限24h，不存账号/表单内容于浏览器持久缓冲。

`account_link.effective=false/reason=ASSOCIATION_NOT_READY`，`advertising.effective=false/reason=EXTERNAL_DISABLED`；capability只能unavailable或choice_only。site deny、旧对应拒绝/资格未知可用更具体reason；保存成功与运行分列。R1没有active账户/广告能力，不创建C账户边、订单映射或外发任务。

旧广告选择以独立`legacy_advertising.choice=unknown|allow|deny`只读展示，明确不是本次新notice的选择；不将旧granted迁为新目的grant。新广告allow只保存本R1意向，不调用旧grant。新广告deny须在当前可识别proof范围同步必要停止约束/旧待发退出，保留历史订单快照；不能只保存一个对旧管线无效的false。

新旧广告修改在05内统一串行化：旧无版本allow不能覆盖R1已确认deny，旧deny要推进相应用途版本；切回旧前端或关闭R1开关不能复活已拒绝的用途。未有新proof的普通旧模式保持。site_session拒绝不顺带撤旧广告；“关闭全部”另须各用途实际确认，部分未知不得说全关。

## 4. HTTP形状（05提供，01/04浏览器消费）

同站会话/CSRF/Origin/no-store沿既有规则，变更体最多4KiB、字段严格白名单；不接受viewer/actor/地区/basis/来源字段。原v2完全不接受新参数；不新增公开身份成功写入口。

| 路径 | 行为 |
|---|---|
| GET `/api/v3/measurement/purposes/context` | 只读能力/选择，匿名也可读；不创建测量ID、偏好proof或证据。无依赖/策略时稳定unavailable/off，不回退v1 |
| PATCH `/api/v3/measurement/purposes/preferences` | 单目的命令：`purpose,choice,notice_version,expected_revision,operation_id`；operation_id为UUID，notice必须当前，revision非负整数。只改该目的 |

context统一：`schema_version=3, policy_version=registration-purpose-r1, notice_version=c-purpose-choice-only-r1-v1, is_test=true, eligibility, site_session, purposes, legacy_advertising`。site_session只复用既有真实`choice/effective/reason`，不接受本接口修改。

`purposes`以两目的为key，每项为`choice,effective,reason,capability,revision,choice_scope=current_browser`；不返回内部proof、owner、subject、map或policy文件路径。匿名可看能力准备注册草稿，allow实际写入必须当前web身份；游客deny仍可作为必要退出，不强迫注册。

保存成功返回`schema_version=3,purpose,status=saved|unchanged,state`，state同上；每项分别真实回执。新变更CAS推进revision；同scope/账号/内容/operation重放不重复证据/期限，返回原操作确认及**当前**真实state；同key异内容或跨身份409。旧allow回执不得复活新deny。

notice或revision冲突409回当前真实state；字段422，身份401/权限403/CSRF419；依赖或存储503不假报保存，预算429/Retry-After。R1独立命名空间：context每分钟120/IP，preferences每分钟30/受保护必要会话及180/IP；不消耗v2的拒绝预算，也不由读取/采集请求消耗，身份/payload不能补额度。读取失败时可用最后有效revision或初始0作严格CAS，409不自动重放。deny在选择运行资格失效时仍可保存必要退出；不可把本地暂停称服务器关闭。

客户端先创建必要账号并确认身份，再单独提交确实改动的目的；无请求显示not_requested（非服务端存储选择）。偏好失败只显示“账号已创建，该偏好未确认”，提供继续使用/显式重试，不重发密码/注册或已保存的另一项。草稿仅本页内存，非JS可正常建号、继续必要服务并之后管理。

## 5. 可信身份结果与PHP端口待接

R1仅准入**显式合成策略下**同请求`registration_succeeded|registration_failed`，不是凭不带viewer就认定真实资格。基础C已on且结果目的获准、对应notice已交付才可服务器捕获；无context不创建、不补历史。操作引用为服务端请求随机UUID，不能用viewer/email/必要session摘要当测量key。

创建成功在01必要事务已提交、自动Login清旧上下文前记录；邮件/回程失败不能改成注册失败。失败只用validation/conflict/temporary/unknown短枚举；不含表单字段、邮箱、账号ID、订单或来源。05每次复核拒绝/结束/策略；记录故障不改变身份结果，不持久保存身份→旧C重试映射。

可信结果不加入浏览器可伪造的事件名单；原v2事件入口应继续422拒绝伪造成功。登录/Verified等其他权威结果本批关闭。退出/换号复用结束职责，存储故障也清浏览器测量能力，保留deny且不撤历史订单授权。

00现已提供第7节实际PHP契约、结果DTO和默认不可用实现；05提供方适配及01-B仍须独立交付/复验。05-A模块内服务不是公共端口，01不得直接调用；未接入真实适配前，公共端口显式DEPENDENCY_NOT_READY。

## 6. 接收与下一人工

01-A测必要接受/版本/原模式兼容/真实建号及回程故障；05-A测每用途真实存储/身份scope、CAS/幂等/期限、旧拒绝/模式回退、401/419/409/429/503以及原账务/D05不变。独立库/标记和新迁移，禁止改已采用迁移。

合并PHP端口/01-B及04管理接线后，06另备全新独立注册窗口/合成账号/本地邮件预览及短期策略，08复看中英/窄屏/真实保存状态，00固定GitHub版本后交07新0/3。测试不选可选仍建号、原site deny验证/退出重登仍保持，不重复旧游客小轮；全部允许/单项撤回与异常先工程自动化。付款/渠道/账户边及有序报表分后批，未映射付款“不适用”，不算全体转化率。

## 7. 实际PHP端口 c-reg-r1-php-1

2026-10-31增量，00归属的实际文件已提供；不是新增HTTP接口或原业务版本变更。

| 文件/方法 | 固定职责 |
|---|---|
| `App\Contracts\RegistrationMeasurement::availability()` | 返回`RegistrationMeasurementAvailability::Available`或`DependencyNotReady`；仅说明适配依赖是否存在，不是政策允许、C已启或用户同意 |
| `capture(string $deliveredNoticeVersion)` | 返回可空`RegistrationMeasurementCapture`；只接受01可信服务端已经交付的R1 notice版本，不能直接把POST隐藏字段当交付证明；无现有合法C不创建/补记 |
| `record(?RegistrationMeasurementCapture, RegistrationResult)` | 返回既有`BehaviorRecordingResult::Accepted/Skipped/Retry`；不得抛错阻止账号结果。Retry只指当次临时失败，不允许持久化捕获、身份映射或日后补采 |
| `RegistrationMeasurementCapture` | 不暴露账号/邮箱/C主体或会话；只能由05的同Request能力实现并由其再次验证。`__serialize()`必须抛异常，浏览器不接收此对象；伪造实现/别的请求/已结束能力必须Skipped |
| `App\Data\RegistrationResult` | 仅`event_name: RegistrationResultKind`与可空`failure: RegistrationFailure`；成功必须null、失败必须validation/conflict/temporary/unknown之一；无任意properties/身份字段 |
| 默认`UnavailableRegistrationMeasurement` | 已由AppServiceProvider绑定，availability显式DEPENDENCY_NOT_READY、capture=null、record=Skipped；没有数据库/事件写入，不假报记录成功，不要求身份流程排队等依赖 |

05负责用真实模块内`RegistrationObservations`适配/绑定该接口，原`RequestObservation`实现公共marker且仍拒绝序列化；返回Available也必须在每次capture/record重验现有C、R1合成策略/期限/结果开关、已交付notice及拒绝状态。不能在注册前后增身份边或把v1 viewer事件改投C。

01-B先在R1页面真实呈现对应说明时以服务端受保护状态记交付版本，在同一次注册请求捕获；只在必要事务已提交且自动登录清旧C之前记录成功，必要创建失败按短枚举记录失败。必要条款拒绝/失败、邮件失败/回程不明不能伪造成功；邮件失败发生在账号提交后不能改记注册失败。端口缺失/抛错/Skipped/Retry均不改账号、条款收据、付款或登录结果，不自动重发注册。真正分项选择继续消费第4节v3 HTTP，不把这个结果端口当可选同意保存口。

00已独立执行默认不可用/结果类型与未知字段专项3项17断言通过；真实提供方及01可信时序组合仍待05/01交付。新增端口不代表R1整体通过或当前可人工。
