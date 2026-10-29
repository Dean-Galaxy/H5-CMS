# 05 analytics-v1 浏览器实现与接收补充

2026-10-29。业务契约1.0.5附加analytics-v1；本请求中公共字段/三态/独立landing/退出与撤回/点击匹配已经00分别以ecf132b、f629a04、c9a1d9b、0e026b7、fbafae0接收。最终以docs/contracts/analytics-v1.md为准；不是另起待决策规则。

- `/api/v1/tracking/events`：events最多20、整包32KiB；每项event_id/event_name/properties，空properties进入单条校验，未知权威事件与伪造viewer/source/金额等拒绝。同ID同内容幂等，不同主体/内容冲突拒绝。暂时失败503稳定ID重试；422/413为整包拒绝。
- 公共page_instance_id/play_instance_id为UUID，后者不是媒体播放许可ID。固定path不收query；剧/集及对应关系通过CatalogReader复核。位置不当观看；watch_progress累计单调计增量，服务端有实际经过时间上限；完成还须已有开始且观测达到可信分集时长的90%。仍属已同意客户端观测，不能承诺绝对反作弊。
- 独立POST `/api/v1/tracking/landing`先确认来源，再发分析page/landing/drama事件。通用landing_view不再自动建来源；存在待确认广告候选时入口事件503可重试，避免抢先固定缺失点击上下文。临时目录故障503；无效/停用不可换剧。广告同意独立于分析同意。
- 可选click_context仅fbclid/ttclid/fbc/fbp/ttp，ASCII格式及每值512bytes、整包4KiB；/r只转发当前平台点击ID，未同意前只在导航URL、不入source/行为/持久缓冲。来源保存最小平台字段并加密；当前fbclid优先构造官方格式fbc，没有真实fbclid不编造；缺点击标识有安全诊断。page_url无query、日志/报表无原匹配信息。真实平台匹配未验。
- D05：7天同意后最后有效推广点击，普通访问不覆盖/延长，建单快照固定。改剧/平台/像素产生新code，旧链接停用；旧单/付款/订阅快照不改。平台像素编号/环境不可直接替换，另建资产。
- 退出/换号只解绑活跃subject/source/pending并回unknown；不撤销旧单授权。明确撤回才撤销。登录观众按IdentityContext可信viewer撤回自身05隐私证据及本浏览器匿名证据，不影响其他账号；匿名仅可撤销本浏览器加密签名证据，最多20项/90天，不声称全账号撤回。活跃同意Cookie7天，普通浏览不延长source。
- 03可靠事实按Accepted/Skipped/Retry；退款fact_version为递增累计快照可纠正到0，不相加；金额只读BillingAnalyticsReader当前事实。生命周期精度问题由03的3d6278c与新增200900迁移修复，05严格按真实微秒和事实时同意消费，不倒补；provider付款整秒只允许同单同秒checkout因果排序下界，原occurred_at不改。
- 只在显式ACQUISITION_ANALYTICS_ENABLED=true且模块就绪采集；H5_LOCAL_MODULES=acquisition仅自身local/testing；广告transport=disabled下本站分析可独立使用。生产加载/部署及真实平台外发仍由00接收放行。无来源不阻塞订阅。

04所需落地/隐私/脚本插槽现由00+04正式接入：共享网站布局ready条件include、04 tracking partial/analytics.js、两种用途同意/拒绝/撤回、可见详情独立landing、真实播放器与表单行为。05不改共享布局、身份、内容或账务模块；临时错误/缺依赖不伪造成功，不以追踪阻塞业务。

00已接收权限兼容纠偏：非Owner复用AssetAccess::query('link')，自己或当前获分配链接；他人撤销即不可见，自有不因冗余分配撤销而消失；报表/财务/维度/诊断同范围，无空范围全站回退。公共契约由00更新，不新造权限。
