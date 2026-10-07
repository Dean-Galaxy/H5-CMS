# 投流只读依赖契约 1.0.3

2026-10-07，00 接收 05-001 中两项独立工程依赖。基于 `h5-audience-v1` = `501dc2584b8973239669e346fd12dcca19e6ce47`。本补丁保留原接口，新增两个内部 PHP 只读接口；不开放新的公开 HTTP 路径，不确认 D05，不启用 Acquisition、广告发送或外部测试站。

## 01 提供的员工候选

`AcquisitionStaffDirectory`：

| 方法 | 输入与输出 |
|---|---|
| candidates | `(?string $cursor = null, int $limit = 50): AssignableStaffPage` |
| candidate | `(string $staffId): AssignableStaff` |

`AssignableStaff` 仅含 `id`、`name`、`role`、`active`。role 必须为 acquisition，active 必须为 true。分页值为 `items` 与可空 `next_cursor`，limit 为 1–50。不存在、停用或不再属于投流角色的 ID 均返回 `NOT_FOUND`，格式非法用 `VALIDATION_FAILED`。

01 实现中每次调用都通过当前服务端员工身份、MFA 和 `StaffManage` 权限核验，因此只有负责人可用；不能依赖 05 隐藏按钮代替权限。名单不含邮箱、联系方式、二次验证或认证信息。名单不把负责人列为普通分配对象；负责人已有全局访问权不依赖把自己加入像素分配名单。

01 定义稳定排序，分页至少用 ID 消歧；游标应有完整性保护并绑定本接口用途，不接受任意查询片段，非法游标用 `VALIDATION_FAILED`。05 从某页选择不等于保存时永久有效：新增或修改分配前调用 candidate 复核当前资格，已选择的记录独立按 ID 回填，不能因不在当前页而丢失。用户随后被停用时，现有身份与会话失效规则继续执行。

## 02 提供的可投放片单

`PromotableCatalog`：

| 方法 | 输入与输出 |
|---|---|
| promotable | `(?string $cursor = null, int $limit = 20, string $locale = 'en'): PromotableDramaPage` |
| promotableDrama | `(string $dramaId, string $locale = 'en'): PromotableDrama` |

`PromotableDrama` 仅含 `id`、`title`、`locale`、`country_code`、`published_episode_count`。country_code 固定 US，locale 为 en/zh_CN，公开集数至少 1；不提供任何媒体地址、素材路径、付款或观众身份。分页为 items/next_cursor，limit 1–50；语言逐字段使用已存在内容回退，不自动翻译。

02 每次核验当前员工、后台 MFA 和 `PublishedCatalogRead`：负责人、内容运营、投流按既有权限可读，客服和观众不可读。该接口描述美国投放库存，不使用当前员工访问时的观众地区 ZZ，也不把它改成 US。02 按真实当前时间筛选：剧目已发布、美国授权在 `[license_from, license_until)` 内、至少一集已实际公开且媒体当前可用。公开数由 02 现有发布事实产生，不能用总集数替代。

不存在、下架、美国未授权/过期或不满足可投放条件的单剧统一 `NOT_FOUND`。排序明确并以 ID 消歧；游标有完整性保护，至少绑定用途、美国范围、locale 和排序条件；数据版本变化后的过期游标按现有 Content 约定明确失败，不能跨条件继续。05 保存/修改推广目标前复核单剧。之后每次观众落地和申请播放仍走公开 CatalogReader/PlaybackAuthorizer，内部片单绝不授予播放或绕过地区。

## 未就绪与兼容性

00 注册两个明确的未就绪实现，01/02 在各自 ServiceProvider 中绑定真实提供方后覆盖。缺提供方必须抛 `DEPENDENCY_NOT_READY` / 503，不能返回空名单或借用样例；真实提供方返回空页才代表当前没有候选。Identity/Content 的模块级 ready 不保证本新增能力已实现，05 要处理具体接口的缺项。

CatalogReader、IdentityContext、AttributionService、PaymentSettled 及原 DTO 签名不变，没有迁移或依赖变更。尤其 `AttributionService::snapshot(null)` 继续表示无可验证标记，不在本次暗改成“读取当前会话”；浏览器归因上下文和 04 插槽另行发布。

D05 的 7 天、点击覆盖、隐私和版本规则仍是 05 提议，00 已向用户提出一次集中选择，未收到答复前保持关闭。受控 staging、可信地区来源与 Stripe 测试门禁也另行协调，不因契约升级放开生产/外发。

## 分工与验收

- 00：本接口、DTO、OpenAPI、正反样例、公共绑定和契约测试；串行集成。
- 01：员工读取实现和自己的 provider 绑定；验证 MFA、负责人权限、角色/停用/未知 ID、分页与不泄露联系信息。
- 02：片单实现和自己的 provider 绑定；验证角色/MFA、实际发布/素材/美国授权、边界时刻、语言和游标。
- 05：同步本固定补丁后使用精确接口；提供方未接收前，在 testing 场景使用明确样例，普通页面如实显示依赖未就绪。不要直接读取别的模块表。
- 06：把此版本作为增量记录；接口结构与样例通过不等于真实目录、D05或外部部署通过。

候选由 00 验证后发布固定提交；01/02 实现分别交回自己的完整 SHA 和交接证据。新增的 10 个正反样例区分最小成功响应、真实空页、停用/错误角色、联系方式泄露、零公开集、错误国家和媒体地址泄露。
