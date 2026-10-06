# F01、F02 共同读取与提交约定 v1

版本：`1.0.0`。00 维护；02、03 提供，04 消费。本文件与 OpenAPI、PHP 类型、样例一起发布。这里定义模块怎样对接；当前工程尚未实现真实内容或订阅，入口继续返回 `503 DEPENDENCY_NOT_READY`。

## F01：取消后的原订阅怎样恢复

已确认业务规则不变：取消即停止未来续费，已付权益保留。原订阅未到期且仍可恢复时，用户主动确认原约定价和下次扣款时间，再恢复同一订阅；不立即扣款、不重置到期、不重给优惠。后台后来修改的新购价格不影响本次恢复。

04 按以下顺序消费：

1. 读 `/api/v1/me/membership`。`allowed_actions` 包含 `resume` 时可展示恢复入口；这只是入口提示，不能代替提交时检查。
2. 用户进入恢复确认页时，GET `/api/v1/billing/subscription/resume-quote`，取得本人 `ResumeQuote`。这一步不改变续费、付款或权益；允许 03 保存确认快照。成功响应带 `Cache-Control: private, no-store`。
3. 展示币种、原约定下次金额、下次时间及“不立即扣款，当前到期日不变”。用户主动点击确认后才 POST `/api/v1/billing/subscription/resume`，使用同一观众会话和 CSRF。
4. 成功时使用返回的 `Membership` 更新页面。超时或 409 时先重读会员状态；需要新凭证时重新展示并让用户再次确认，不自动重试成一份新订阅。

| 返回的 ResumeQuote 字段 | 提交的 ResumeInput 字段 | 来源与含义 |
|---|---|---|
| quote_id | quote_id | 03 生成的站内 UUID，绑定当前观众、原订阅及本次确认快照 |
| subscription_id | 不提交 | 站内原订阅 ID；不得使用浏览器传入的订阅 ID 改换目标 |
| confirmation_version | confirmation_version | 原订阅恢复条件的版本；不是网站当前新购 price_version |
| currency | confirmed_currency | 首版 USD，展示并回传，不允许前台换汇 |
| next_renewal_at | confirmed_next_renewal_at | 原订阅恢复后的下次扣款时刻；UTC ISO 8601，前台可格式化展示 |
| next_renewal_amount_minor | confirmed_amount_minor | 原约定续费金额，美元最小单位整数；1200 仅表示测试用 12 美元 |
| expires_at | 不提交 | 服务器的凭证失效时刻；客户端到时停用确认并重取，服务器始终再次检查 |

`Membership.renewal_enabled=false` 时，`next_renewal_*` 保持 null，表达当前没有已安排的续费；恢复页面必须使用独立 `ResumeQuote`，不能拿 `Offer` 或 `access_until` 推测。原来的取消中会员样例因此仍有效。

03 的实现必须遵守以下规则：

- 只有本人原订阅处于可恢复、尚未到期、已停止未来续费且付款状态已明确时，才发放凭证。没有可恢复订阅或已结束返回 409 `STALE_VERSION`；付款尚待核实返回 409 `BILLING_PENDING`。不能在这种情况下自动创建新订阅。
- 凭证有效区间是签发后至 `expires_at` 之前；默认最多 5 分钟，并取已付期结束、下一扣款时刻中更早者。到达边界就失效。此时限用于避免旧页面误操作，不决定 D02 的新购未付款保价规则，不改变用户原订阅价格。
- 服务端保存或使用可验证的凭证绑定，不能只信回传字段。本人、原订阅、版本、金额、币种、时刻、有效期和当前可恢复条件全部相符才可执行。外部平台结果未知时先核对，不能通过猜测金额生成凭证。
- 影响恢复的原订阅状态、下一时刻或条款改变时更新 `confirmation_version`。取消、恢复及其后再次取消必须使旧确认不能跨操作重用；单纯网站新购调价不改原订阅的约定价。
- 幂等范围为“本人＋quote_id”。同一凭证及相同确认在原操作已完成且未发生更新操作时，返回当前已核实会员状态，不再调用平台重复恢复；即使凭证后来过期，也允许只读返回这次已核实的完成结果。尚未执行的过期凭证不能启动操作。进行中返回 `BILLING_PENDING`，先核对平台结果。不同请求内容或已出现后续取消等变化时返回 `STALE_VERSION`，不能让旧重试撤销新取消。需要原子并发控制和可恢复的操作记录。
- 未知或他人 quote_id 统一 404 `NOT_FOUND`；输入结构无效 422 `VALIDATION_FAILED`；凭证过期、条款不匹配或版本/状态改变 409 `STALE_VERSION`。服务缺失 503；未登录 401。不得把这些响应当作已恢复。
- 成功必须核实同一订阅已恢复未来续费；不立即扣款、不增加本期权益、不改变原到期，不重获首周优惠。客服仍无恢复权限。

配套样例：`membership-canceled-paid.json → resume-quote.json → resume-input.json`；`offer-after-price-change.json` 特意用 1500，对照原订阅的 1200，证明消费方不需要从新购报价凑恢复字段。样例时间固定在 2026-10-07 12:00 UTC 场景，不作为今天仍有效的真实凭证。

## F02：首页、分类与剧目如何衔接

| 读取接口 | PHP 接口 | 返回与消费方式 |
|---|---|---|
| GET /api/v1/home?locale=en | CatalogReader.homepage(locale) | HomePage：locale、categories、banners、recommendations；04 渲染首页 |
| GET /api/v1/categories?locale=en | CatalogReader.categories(locale) | CategoryList：locale、items；供独立分类页，内容及排序与相同语言首页 categories 一致 |
| GET /api/v1/catalog?category_id=…&locale=en&limit=20 | CatalogReader.catalog(cursor, limit, locale, categoryId) | 原 CatalogPage，仍为 items、next_cursor；category_id 可省略，省略查全部公开目录 |
| GET /api/v1/dramas/{id}?locale=en | CatalogReader.drama(dramaId, locale) | 原 Drama；Banner、推荐和分类列表最终都进入同一剧目详情 |

最小结构：Category 是 `id/name/sort_order`；HomeBanner 是 `id/title/image_url/target_drama_id/sort_order`；DramaCard 是不含分集列表的公开剧目摘要，加 `sort_order`。全字段以 OpenAPI 为准。首页无需包含媒体文件地址，播放仍使用 PlaybackAuthorizer。

02 提供方负责：

- 返回启用的分类；分类可以暂时没有公开剧目，点击后得到空目录。不存在或停用的分类统一 404；非法 UUID、语言、limit、游标为 422。不会为了凑内容返回草稿。
- 各数组按 `sort_order` 升序，同值按站内 `id` 升序；04 按数组顺序展示。目录同样按 02 维护的目录排序值、id 升序，筛选不另换规则；分页游标绑定分类、语言及排序版本。条件改变或排序版本过期的游标返回 422 `VALIDATION_FAILED`、`fields.cursor`，04 清游标重读第一页。
- Banner 只支持跳转站内剧目，不新增任意外链或脚本功能。04 用 `target_drama_id` 构造自己的站内详情路径；图片只接受服务端控制的 HTTP(S) 图片来源，不能把字段当 HTML 执行。
- Banner 目标和推荐剧目必须已经公开、未下架，至少一集公开，并符合当前内容授权可见条件。下架或授权失效后，停止返回该 Banner 和推荐；原详情与分类目录也遵守同一可见条件。02 负责让缓存及时失效、在读取时重新检查到期，不把旧快照当永久发布证明。
- 缓存或页面已打开时，旧目标可能已下架；点击后详情返回 404 `NOT_FOUND`，04 提示内容不可用并可刷新首页，不从旧快照绕过授权。
- `sort_order` 只表述顺序；后台新增、编辑、排序、启停权限及保存操作归 02，在 Content 内实现，不由 04 直接改内容表。

语言规则：只接受 `en` / `zh_CN`，省略默认 `en`。04 按已确认的浏览器语言/手动选择规则传参。02 对标题、简介、分类名、Banner 文案逐字段优先取请求语言，缺失时使用另一种已提供语言；必填标题/分类名/Banner 文案两种都没有则不能公开，简介两种都没有可为空字符串。`locale` 表示请求处理语言，不是“所有文字已翻译”的承诺。纯净视频、字幕、会员币种和授权地区不随切换语言改变；不得生成虚假翻译。样例中文页面保留英文剧名，演示回退。

空内容使用 `home-empty.json`；它只适用于真实提供方已安装但没有公开内容。提供方缺失仍是 503，04 显示待就绪或加载失败，不能自动把文档样例放到普通运行环境。

## 本轮与后续验收边界

本轮检验 OpenAPI、正反样例、PHP 消费、接口/路由一致性、访客与观众会话边界及未就绪行为。共享样例的读取和组装验证字段足够，不证明业务提供方已经执行成功。

| 后续责任 | 必须补的真实业务测试 |
|---|---|
| 03 | 其他账号/未知凭证拒绝；到期边界、版本过期和改价确认；篡改但仍符合 Schema 的金额/时间拒绝；同凭证并发重试；恢复后再次取消再重放；平台超时核对；恢复不扣款不重给优惠；Stripe 官方测试 |
| 02 | 实际后台改分类/Banner/排序，首页随之变化；草稿、下架、授权到期和缓存过滤；非法或跨条件游标；真实中英文回退；媒体仍私有 |
| 04 | 真实页面显示确认信息，用户明确确认后提交，409/到期重读并重确认；分类到列表、Banner 到详情的点击；语言/空态/503/目标下架反馈 |

测试代码不代替上述实现。本轮不创建、启动或提前完成 02–06。
