# 05 真实推广落地成功GET接点接收记录

最初建议已由00统一调整并发布真实纯提交`fc78494021c34d3bc77fff37795813a0225bb76e`（parent a5），05正常单取为`ce428fc`。公共接口为`PromotionLandingEvidence::record(string $dramaId): void`，默认Disabled实现空操作；04仅在实际目标剧HTML成功GET渲染后调用，异常不影响200/播放。05只绑定模块实现，不跨改04或基础共享文件。

以00后续明确冻结为准：首次成功GET只留与原pending绑定的10分钟功能页证据（drama/link/pending key/时间），不在同意前存额外IP/UA或创建广告事件。用户在该页面原隐私组件首次同意、可见confirmLanding后，05用当时有效广告grant和当下可信请求上下文生成事件，occurred_at取同意后当前时间。**无需人为刷新，不追溯补报同意前浏览时间。** 此规则替代最初“同意后须重新GET”的建议，最初建议未成为已确认事实。

05独立非金融ledger严格一条/landing_key；精确绑定真实source、privacy、像素版本/test用途，只在受控staging的Meta测试资产使用既有专用凭据。独立专用CLI最多10项、不扫付款、不走通用队列，无自动重试。默认发送继续关，00核具体有限窗口才接外发；source/ledger/发送各自拒绝撤回，C/R1及分析仍关，旧probe固定路径不松。04成功GET接点尚待对应候选组合验证，不能用模块夹具声称已有真实页面全链通过。
