# 直连联调 direct-test-1（00冻结增量）

2026-11-01，本人在07批准“平台原生归因＋本站最小业务/漏斗/诊断”，并要求先补工程后收敛同事材料。统一原起点54633d43；默认关闭，不采购第三方，不扩跨渠道归因/成本API。既有1.0.5业务语义不改，以下为兼容增量端口，旧JSON/付款事件不回写。原R1/C2/周四数据、环境及期限保持；真实官方测试仍须实际资产/用途/域名与受控入口准备，不因本契约自动启用。

## 00 公共准入和兼容端口

- `H5_ACQUISITION_STAGING_TEST`默认false、必须真实bool。仅APP_ENV=staging且controlled_staging=true可显式加载既有Acquisition provider；production及local/testing伪装此位拒绝，旧local测试方式不变。所有TLS/Host/无代理、SMTP TLS、非模拟/C/R1关闭保护继续。
- 模块boot、凭据命令非本地准入和每次HTTP前调用`RuntimeEnvironmentGuard::controlledAcquisitionTestOrigin()`：复核整体Guard、测试位，禁止C/R1/analytics_enabled及synthetic/observations；仅disabled或http，http仍需external_enabled/platform_reviewed显式true。工程从不写真实env或开启开关。此批准只是受控测试工程，非production/正式广告数据放行。
- 新增独立`CheckoutAttribution::capture(AttributionSnapshot $source, CheckoutPageContext $page): ?AttributionSnapshot`，默认实现返回null，不扩大旧AttributionService。`CheckoutPageContext(path, rendered_at)`只能来自04服务端实际渲染的新购draft，path固定`/membership`，时间UTC ISO；不接浏览器自报URL/Referrer替代证据，不含viewer/offer/provider标识。
- `AttributionSnapshot`仅新增尾部可选UUID `checkout_context_id=null`，null时toArray完全省略，使旧6字段JSON/序列化保持。原03订单/续费/outbox链可原样保存该字段，不改指纹、账本或续费策略；FinancialAnalyticsReader仍按原六字段白名单输出，不要求广告私密上下文进入分析端口。

## 04 实际购买页接线

- 只在`GET /membership`实际展示可提交的新购Offer表单时，给已有服务端offer draft保存规范path和rendered_at；首次`POST /membership/checkout`在现有账号/报价/draft/有效期验证后、建单前调用新capture，并将结果随已有attempt来源固定，重复/未知结果重试不重新捕获或后补同意。原付款幂等和回程不改。
- 只捕可信同站来源，Origin/Fetch-Metadata不符不得捕获；没有有效页面证据的旧draft/API直接建单不伪造页面。可保留原有效六字段来源用于既有D05，但不产生新context；缺同意/不可用端口/捕获异常不妨碍合法购买，外发由05缺上下文明确阻断。跨账户/过期报价沿原规则拒绝，不能为了广告放宽购买校验。
- 不改恢复续费入口，不把付款成功页或API地址假作购买页。控制测试仅广告测试告知/现用途选择，分析入口保持不可用，不照搬R1本机条款作正式政策。

## 05 受控广告用途与真实上下文

- 将上述测试位当仅广告测试入口：分析/C/R1/增强身份关联不运行；`TrackingContext`可支持广告上下文/显式广告选择，但subject/BehaviorRecorder/浏览器行为事件及syncViewer关联必须保持禁用。现拒绝/撤回/旧拒绝桥/D05规则复用。受控页面响应须明确分析不可用，不把分析grant悄悄保存为成功；不因测试位设置任何grant或清旧拒绝。
- 实现capture：重新读取当前有效来源/广告同意并与传入6字段逐项匹配，验证page.path固定和页面时间不未来/不超30分钟及请求上下文。新增本模块迁移私密加密checkout context（内部UUID、source/pixel及版本绑定、规范无query/片段的真实HTTPS页、当前直连IP/UA、捕获/保留期限），不加viewer/账户身份映射。只引用现获准的对应平台click/cookie标记，不重写落地来源/点击期限。失败不伪造上下文。
- 05消费PaymentSettled新增可选context引用，在发送时验证与已固定source/pixel版本/平台绑定及用途/撤回；缺context、无效或旧冻结不合新最小白名单的载荷明确阻断并显示安全原因，不更改已冻结载荷或旧付款。controlled staging只发is_test=true＋environment=test，拒绝模拟来源和生产资产/实付款；不得把在用生产Pixel改标test或假源改真。续费在此新测试通道暂不放行，等待独立口径/真实上下文决议；旧local既有语义不改，不自动改正式续费政策。
- 新载荷白名单仅Purchase、真实结算UTC时间、稳定event_id、USD和实付value、Meta真实website action_source、规范真实交易页及独立获准的对应平台IP/UA与fbc/fbp或ttclid/ttp。移除transaction_kind/discount_applied外发字段（仍留本站事实）；不发剧名/集数/播放、姓名/邮箱/电话及其新增哈希、原始内部ID、查询URL/Referrer，不把预期LTV当金额。不能靠换假页面隐瞒业务。
- 本步复用现Gate/Worker、重试与像素/收件/发送状态表。CLI允许上述受控staging配置，不接受生产；就绪提示仍不代表官方收到或归因成功。若现诊断已可说明原因，不另建监控/归因后台。私密context清理纳入现prune，期限不超过原广告上下文90天，当前一期上下文另设短期并不延长原来源/同意。

## 接收与真实测试边界

00先交实际公共代码后04/05接线；03本轮只读核对已完成，无必需新账本改动。06只核新增默认关闭/预检与直接受影响隔离组合，08只有实际新增文案/展示偏差才复看，不全员扩工。01邮件故障恢复/原SMTP已具备，无新缺口不派额外重做；GeoIP/正式注册告知与实际云部署仍独立待接，不能用本轮广告测试位替代。

仅新端口、默认旧兼容、受控准入、可信页面/拒绝/重试固定、ad-only零分析身份关联、载荷最小化和直接影响的发送用例验证。未改历史证据复用，不重跑R1人工/全套/旧支付组。最终源码扫描固定GitHub回读后再集中人工；域名、资产授权/专用测试资产和私密凭据缺项由07按实际最后结果一次合并，不现在发问卷。官方HTTP接受、Test Events实际显示、广告归因各自记，不以本地测试/配置成功代替。
