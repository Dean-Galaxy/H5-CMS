# 01 analytics-v1：观众身份结果埋点交付

2026-10-29。01所属实现和自测完成，待00/05/06组合接收；不部署当前21f33的C2站或周四站，不开放广告外联。业务契约1.0.5及旧标签不动；附加契约analytics-v1。

## 固定版本与模块边界

- 既有01分支 `codex/01-identity-maintenance`，工作区 `/Users/hejunxiang/.codex/worktrees/h5-01-identity-maintenance/H5`，从d0f5d4e正常合入32e878f，再正常合入ecf132b。实现提交 `57029ca0d386a986e976dc928a2dc4120baa0a16`。
- 仅改Identity动作/服务/中间件/provider、identity模块路由和自身测试。没有更改公共接口、应用根配置、05表、支付账本或真实账号；未读取其他工作区的.env/凭据。
- 通过公共BehaviorEventRecorder::record()提供最佳努力身份事件；recordFact新接口已同步，测试替身也实现。01不新增可靠outbox，不保证采集暂时失败后自动补传；false/异常允许本次采集缺失，不能改变认证结果或伪造成功。业务财务可靠事实仍由03/05负责。
- 匿名主体、consent、source/link解析、数据入库及去重均由05负责。01不生成匿名/来源ID、不访问05表，不在普通运行中注册测试采集器。

## 事件与键

| 事件 | 真实触发与参数 | 去重键 |
|---|---|---|
| registration_succeeded | CreateViewer已真正落库，服务端标记真实viewer UUID；即使后续邮件通知失败仍是创建事实。正常注册自动登录不再多计login_succeeded | identity:registration:{viewer UUID} |
| registration_failed | 未创建账号的真实验证/限流/服务拒绝；仅error_code | identity:registration:failed:{服务器request_id} |
| login_succeeded | Fortify真实观众登录完成并再生会话后，web guard已有当前观众；仅viewer_id | identity:login:{viewer UUID}:{最终session ID的SHA256} |
| login_failed | 真实凭据失败、缺字段、限流或其他拒绝；仅安全error_code，不查询或提供失败邮箱的viewer ID | identity:login:failed:{服务器request_id} |
| email_verified | Viewer的Verified事件且web请求；DB afterCommit执行，事务回滚不采集；仅viewer_id | identity:verification:{viewer UUID} |
| logout | 观众退出时先记录，随后clearBrowserContext，再Auth::logout/invalidate；仅viewer_id | identity:logout:{viewer UUID}:{退出前session ID的SHA256} |

error_code固定：VALIDATION_FAILED、INVALID_CREDENTIALS、RATE_LIMITED、AUTHENTICATION_REQUIRED、NOT_AUTHORIZED、INTERNAL_ERROR、REQUEST_REJECTED。不含验证文案、邮箱、密码、令牌、原始session credential、任意输入或异常堆栈。

关键成功键可在同一事实/会话重复调用时保持一致。失败事件同一服务器请求重试同键，另一次HTTP尝试是新request_id。01提供稳定键，05持久去重负责最终“计一次”；测试替身的去重不是05生产入库通过证书。

## 接入细节及异常保护

1. TrackViewerAuthentication包装原有命名ThrottleRequests限制（identity-login/identity-register），再调用原Fortify流程，捕捉实际结果。没有改限流额度/验证码/密码规则。单独把custom middleware列在throttle之前会被Laravel优先级排序移到外部，故在模块内包装既有限流器以覆盖429；guest/会话/CSRF保持原路由层保护。
2. 登录会话再生不flush现有合法匿名/来源属性；Identity只保存本模块identity.last_viewer作为当前账号边界。直接guard换号先调用05的clearBrowserContext；若消费者抛错，旋转整个会话、仅保留Identity已建立的登录键和受控返回信息，杜绝旧分析上下文串给新观众。没有枚举05的私有session键。
3. 退出按record→clear→logout/invalidate顺序；record返回false或抛错仍尝试clear，clear抛错仍正常退出并由invalidate清空会话。已退出再请求被认证拒绝，不重复记录logout。
4. staff登录、staff Failed/Verified、MFA不记录观众行为；原安全审计继续执行。统计与安全审计是两条独立链。

## 自测及证据

| 文件 | 结果和版本限制 |
|---|---|
| identity-contract-regression.txt | 相同生产实现上Identity全目录+AnalyticsContracts+AdminPanelIntegration共107项849断言通过，6.53秒。此后仅给新增专项补强失败/退出重试及回滚断言，生产代码未改，不把新增断言数量补算入该次输出 |
| viewer-behavior-tests.txt | 最终提交专项13项124断言通过，1.46秒；正常真实注册/登录/验证/退出HTTP动作，来源/匿名属性保留、真实当前guard、成功/失败去重、错误枚举与脱敏、限流、事务回滚、staff隔离、同意未知/拒绝、no-op/false/throw无损、换号与clear异常兜底 |
| contracts.txt | OpenAPI1.0.5结构和59正反样例通过；analytics公共契约另在上述回归3项17断言中通过。Python环境提示LibreSSL版本警告，结构校验本身完成，无网络送信 |
| dependency-audit.json | 本轮在线composer audit --locked发现1项medium：锁内symfony/mime v8.1.7，CVE-2026-45070 / GHSA-vqc8-7275-q272（MIME参数名邮件头注入）；abandoned为空。包审计给出8.1.10及7.4.22等修补界限，未在本模块擅自升级。已向00回传公共安全补丁审查。尝试回读公告页工具不可达，不能说已独立完成官方公告全文核验；依据为本次Composer返回 |
| source-manifest.json | 7个本轮源码/测试SHA256与固定实现对应；无依赖、迁移或公共文件改动 |
| stopped-status.json | 仅01测试PG6269曾启动；web/queue/scheduler/HTTP未启动，最终五项false，保留原数据与素材 |

测试的BehaviorEventRecorder是testing进程内专用替身，模拟同意后接受、拒绝、异常和去重；不访问05数据库，不触发真实广告请求。现阶段05真实实现尚未在01组合，不能宣称真实分析报表/广告同意门禁/平台送达已验。Notification::fake仅拦截本轮合成观众邮件，不访问真实邮箱；有效验证仍走实际签名URL/nonce/数据库流程。原真实邮件送达边界不变。

初次专项10过/2失败：定位到限流优先级导致429没被包住，以及PHPUnit HTTP helper每次请求的session装载使跨请求ID对比不成立。分别完成模块包装与改为核对logout和clear在同一请求、invalidate前后顺序；后续最终专项全过。失败原始输出只留本地忽略目录，不把合成session值带入交付。

## 交00及05/06验收

- 正常接收本分支，固定组合及GitHub留存由00负责；本模块未自行推送、部署或移动任何标签。
- 05实现需接受受信viewer_id和上述error枚举；注册后通知异常可能当前web guard尚未登录，但账号已创建，viewer_id仍为服务端事实，必须先按实际consent门禁决定是否收录，不能信任浏览器同名参数。
- 05/06在新隔离组合用正常同意→匿名页面→注册→验证→退出→另账号登录链路，证明持久库同一事实只计一次、无同意不建立主体、退出/换号不合并，且广告关闭时零外联。01替身用test.*属性只是合同消费验证，不是生产会话键。
- 00对本次共享依赖medium告警单列安全补丁与回归，不在C2真实窗口热更。原生产支付21f33、8406/5906/桥隧道、周四8377/5877未触碰。
- 产品经理本轮无需申请资料或操作；新分析入口应等05/组合接收后由00/07给出，不能在当前支付站寻找新统计功能。
