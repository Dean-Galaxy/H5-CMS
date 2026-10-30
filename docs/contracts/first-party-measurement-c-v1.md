# 第一方游客会话分析 C：工程核心约定 c-core-1

2026-10-30｜00接收。标识 `first-party-measurement-c-v1`，首批实现范围 `c-core-1`。本人已选择C；本文件**只冻结默认关闭的隔离工程核心，不核准真实地区/受众采集，不放行生产或广告**。共同业务1.0.5与analytics-v1保持，不移动旧标签。

依据：05-003候选75990af、08 C交互5a48b95、06只读计划a05b9e0、00-46。三份文档已单独接收。经营/关联、州与受众条件、处理商及部署证据仍未全部核准；本人关于主要成年受众的陈述不等于每位访客已验证成年。市场/主体私人答复不写入本公共契约。

## 1. 首批核心范围与隔离

05先实现自有模块内的C目的/政策门控、短期伪名/会话、告知后start、基础事件、偏好与拒绝兼容、期限清理及合成验证。原v1管线、订单/会员/来源/付款outbox不改；同页不能同时运行两条采集管线。01/04消费者及03异步付款映射另批接入，不以本文件直接唤醒这些任务。

C总开关默认false，政策解析默认unknown。非local/testing保持拒绝；既有Acquisition生产Guard不变。C合成政策仅供显式隔离local/testing和自动化测试，必须标is_test=true；不能把原local_us_test国家码当实际资格、根据客户端头/请求填allow或在现有站启用合成策略。真实核准政策尚不存在，不写US-*白名单。新AN-M01保持关闭，原C2/周四不动。

新数据用05独立新增迁移/命名空间隔离，不修改已采用迁移，不把原v1主体或真实旧付款补成C样本。不得复制其他工作区.env/数据库/媒体/秘密或原版资料。新表只写本模块事实，不跨模块读写身份/账务表。

## 2. 三用途与当前能力

| 用途 | c-core-1实际范围 | 不能伪称 |
|---|---|---|
| 本站会话分析 | 合成已核准策略、告知交付且未拒绝时可在隔离环境演示；真实环境默认off | 政策允许不等于用户主动同意；伪名不等于匿名 |
| 可选账户增强 | 预留公共状态，当前effective=false，reason=DEPENDENCY_NOT_READY；不存viewer边/账户画像 | 不根据登录/注册/旧analytics=true自动允许，不假保存granted |
| 转化映射 | mode=off/effective=false；不建order/payment映射，不扫原账务 | 无获准映射的付款列只能不适用，不能记0%或浏览器成功 |
| 广告 | 旧广告选择仅按原契约读取；本C管线无任何写来源/SDK/外发能力 | 不从C生成D05广告授权，不在C存click ID/IP/UA/URL |
| 普通UTM/渠道 | 首批不采、不缓存、不入C上下文；未来单独核定 | 不把query、referrer或广告source换名接进C |

账户增强、异步转化和渠道后续需新的工程批次/实际适用与告知范围；不要求用户重新确认已选C，但不得绕过其已确认的分用途边界。

## 3. 状态及资格门控

服务端判定：`eligibility=eligible|ineligible|unknown`，`basis_kind=policy_permitted|none`，`site_choice=unset|allow|deny`，`site_effective=on|off`。版本与有效期来自服务器策略；allow只代表明确用户选择，不是资格凭证，不改旧analytics为granted。

优先级：模块/开关不就绪或内部故障→off；未知/不合格/过期/冲突策略→off；旧有效analytics拒绝/撤回、新site deny或适用限制→off；未交付对应告知或未start→off；其余才可按已核准策略运行。每批收件、续会话和结果事件重新校验，不只首次start。资格失效立即停止新的C处理，不能先缓存后等许可。

旧unknown不改granted；旧granted不扩成账户增强；旧analytics=false/真实撤回须阻断新C，即使策略允许。logout/换号结束当前C主体/会话而不清拒绝偏好，不撤回既有订单的原合法来源。关闭C不更改账务或观看资格。原v1已有独立合法选择仅按原用途处理，不能把C拒绝显示为所有业务停用。

GPC缺失不是许可，有效GPC不自动授予或一概否定全部第一方处理。首批C没有广告发送，不能借此声称原v1全链GPC已完成；实际广告适用/执行另批。若选择“关闭全部可选用途”，后续前端必须同时提交各用途真实关闭，并分别确认，不能只调C API冒称广告已关。

## 4. 标识、事件与期限（本批工程上限，不是法律安全线）

| 对象 | 核心约束 |
|---|---|
| 随机伪名 | 服务端随机至少128bit，只作用本站；创建后绝对7天，不滑动续期；HttpOnly/SameSite，HTTPS下Secure。无指纹/IP哈希/鉴权会话替代，不能靠账号拼回过期主体 |
| 会话 | 空闲30分钟，绝对24小时；超期/退出/换号/拒绝/政策失效结束；新会话与测量主体明确分开 |
| 原始事件/去重/播放状态 | 每条服务器接收后30天；不因重试/查询延长。主体元数据最长创建后37天，及时清空已无用途关联。幂等key/索引不能永久另留一份轨迹 |
| 拒绝/偏好证据 | 必要独立偏好存储，不作为测量标识；候选24个月工程上限，完整法定权利档案另核。Cookie清除后不凭指纹恢复，也不谎称全设备已记住 |
| 客户端缓冲 | 后续04仅当前标签内存，100项/64KiB、5次有界重试、最多30分钟且不越上下文有效期；拒绝/失效清空；不存原始行为到local/sessionStorage |
| 汇总/备份 | 核心不新增持久聚合或备份工具，不默认复制C数据到历史备份流；后续聚合及备份期限/退出重放按06方案落实后才能真实启用 |

事件白名单沿05候选与analytics-v1既有真实语义：page_view、drama_view、play_requested/started、watch_progress、episode_completed、player_startup/stall/error、paywall_view、subscribe_click、registration_view/submit、verification_view、checkout_click、payment_return_view。浏览器不得发送注册/付款成功等权威结果。

只收实例UUID、有限page_type、经CatalogReader复核归属的内部drama/episode引用、locale及事件专属数值/短错误枚举。服务端产生主体/会话/sequence/接收时间、策略与告知版本/测试属性。禁止完整path/URL/query/referrer、邮箱/姓名/口令/表单/自由堆栈、provider ID、viewer/order/payment/source、IP/UA全文/精确位置进入基础C事件。验证CatalogReader不可用时不能绕过校验接受假关系。

真实playing后时间前进才算开始；seek不计观看，进度累计/限幅/节流沿现定义；重放同ID同主体同内容不新增，跨主体或不同内容拒绝。实例仅从C真正启动后生成，不补进入前停留/播放/点击。c-core-1暂不接01/03实际结果，模块内仅可用受信合成结果验证不带viewer的未来接线，不提供公开成功写入口。

## 5. 固定HTTP接入形状

同站必要会话、CSRF、限流、no-store；HTTP参数严格白名单，state mutation不接受viewer/地区/basis。05在自有路由下注册以下路径，根路由加载权仍属00。

| 路径 | 核心行为 |
|---|---|
| GET `/api/v2/measurement/context` | 只读能力/选择/政策状态，不创建C主体、事件或测量Cookie；必要会话不是C标识。即使disabled也返回稳定schema |
| POST `/api/v2/measurement/start` | 仅notice_version与page_instance_id；服务端复核全部门槛，成功才建短期C上下文。表示告知呈现后的技术启动，不叫consent、不修改主动选择；未就绪返回off而不造数据 |
| PATCH `/api/v2/measurement/preferences` | 核心仅接受site_session=allow或deny、notice_version、expected_revision；新目的参数当前拒绝DEPENDENCY_NOT_READY或422，不假保存。只改变明确提供作用域；revision冲突409回真实状态；失败503不假报成功 |
| POST `/api/v2/measurement/events` | `{events:[{event_id,event_name,properties}]}`，最多20项/32KiB，未知字段/不支持事件422，超限413；禁采无C主体/事件、accepted=0；已接收/重放按既有accepted/rejected形状；存储故障503可有界重试 |

context统一字段：schema_version=2，enabled（模块及C开关可用，不是采集已开始），policy_version=`first-party-measurement-c-v1`，eligibility，basis_kind，site_choice，site_effective，notice_version，reason（短枚举，可null），account_link={choice,effective,reason}，conversion_link={mode,effective}，advertising={choice,effective,opt_out_signal}，revision（非负整数）。不返回内部测量ID、策略文件路径、地区原始证据或个人信息。choice只表示真实原选择，无已保存选择是unknown/unset。

start前site_effective=off/reason=NOTICE_NOT_READY；开始后才on。确认start的请求不证明用户阅读理解，客户端后续必须在告知版本确实可见且设置可达后调用；不以关闭提示或继续浏览伪记同意。服务端校验当前版本，不信客户端提交任意告知版本即可跳门槛。

偏好deny在C未开启时也可保存为必要退出，不要求先建C主体。保存成功才可回读已生效，旧响应不能覆盖新拒绝；本地先停和服务器确认要区分。清除浏览器状态的能力边界如实说明。重新allow也需复核策略、告知与start，不在保存allow时自动建主体。

当前account_link effective=false/reason=DEPENDENCY_NOT_READY；conversion mode=off/effective=false。advertising只读展示旧真实选择/效果，未能可靠解析时unknown/false；C API不调用旧consent(true/false)顺带改变广告。原v1不能提供GPC实施证明时不谎称opt_out_signal已生效；字段反映检测到的有效信号而非缺失即许可。

## 6. 报表口径与后续端口

首批核心仅提供05内部查询/合成路径检查，不把新C明细开放给投流员工。未来Owner可见报表须另接授权与样本范围。游客路径与自愿增强付款样本分开；没有映射的付款列“不适用”，不是0。观察中/窗内未观察下一步/资格失效分别表达；不按各阶段独立总数相除。

01/03/04未来内部端口仍由00发布PHP接口/DTO与无依赖绑定；本批不允许模块自己在app/Contracts、app/Data、app/Support或根Provider写另一套。先交核心和API验证，00接收后再给消费者共同起点，不得先引用不存在共享类或复制其他模块服务。

## 7. 最小接收验证

缺模块/开关/政策默认关；本机USflag/伪头不能启C；context无测量写；notice未start零标识/事件；start不写consent；旧拒绝与新拒绝优先、多标签revision冲突；伪权威字段/关系拒绝；重放不重计/seek不计观看；身份与广告零映射/零外发；合成时间下TTL清理；分析存储故障不影响必要业务；干净迁移及已有独立合成库升级。报告标清合成策略/非真实资格，不把测试当真人、正式付款或真实广告证明。

真实启用前仍需：主体/地区/年龄适用、合法告知/条款、实际部署与处理商、权利/删除/备份恢复不复活、真实地区信任链、用途及版本切换、08文案复看与06独立组合/人工验证。没有核准文件，默认策略不得改成allow。

## 工程合成样例

四份公共context样例：[关闭](examples/measurement-c-disabled.json)、[仅合成资格已核但未start](examples/measurement-c-before-start.json)、[合成start后](examples/measurement-c-synthetic-active.json)、[拒绝](examples/measurement-c-denied.json)。均不是运行站输出；示例告知版本`c-notice-c-core-1`仅供隔离工程测试，不能当正式条款已具备。广告与新目的均不因合成C而开启。

start请求结构错误/伪版本422；有效请求但资格未核/拒绝/关闭返回200的off context，不建主体。preferences不接受额外字段，当前请求只处理site_session；expected_revision非负整数必填，冲突409。字段验证沿项目错误规范，不能带回原始输入/身份。上述context字段在关闭和故障保守回读中也保持形状；存储/配置读取故障可503，但不得返回假的已保存状态。
