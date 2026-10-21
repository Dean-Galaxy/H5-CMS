# 03 接入 F09 公共稳定点 8dc6197

2026-10-21。**本次针对性复验及截图已更新，待00接收、08复核及06组合验收；F09继续开放。** 旧版证据保留在[上一轮](../f09-b01-b03/README.md)，不能作为本次公共样式的最新画面。

## 版本与归属

- 公共稳定点 `8dc6197ecc259aedd5dd1b3e2d171b3a4e90e2e9`（含6f622b1/2e6dd1b），正常合并为 `15e9e04bc6b99458de3a8d9b7053c17a78433896`。
- 最终取证/测试候选 **`e86227ea4a0181f79122d346aa1eac8bc4b567d2`**，截图时工作树干净。契约1.0.5 / admin-ui-v1。
- 本轮03只增加浏览器针对性检查；Billing视图、Page、服务与既有 `b6b0b96` 交付逐字不变。嵌套标签后的2px补足继续有效，实测标签到面板22px。无03新迁移、依赖或支付配置。
- 首次合并曾因端点差异被自动审批误判为可能删除03交付；只读核对共同祖先2e6dd1b，确认公共分支仅改5个公共文件、合并无冲突/删除，重试获准。合并后03代码、文档与旧证据零差异，未重置或复制覆盖。

## 复验结果

- [浏览器23项](browser.json)全部通过；正常密码+TOTP，真实Laravel/Livewire/CSRF，沿用本工作区自己的测试夹具，未重新seed或刷新库。未重新执行无关全量账务测试。
- [实际字体与弹窗测量](pairs.json)：完整获批data-srcdoc与实际h1/h2/h3的字号、字体栈及行高一致，旧版普通h2约3px残差已解决；桌面1280×900与窄屏375×812基本页重新拍摄。
- 未保存弹窗：真实空金额触发保存失败后保留原输入和价格标签；1280×900、375×320下正文/滚动容器起点均在X下方，X宽44px，底部动作在视口内。X关闭后仍保留输入，之后可明确放弃。此处没有伪造长说明，也不代替05长内容专项。
- [只读存储8项](storage.json)通过：当前价格仍版本2、1234/321，原两笔订单、订阅、付款、权益和outbox完整摘要不变；原客服测试备注仍一次。针对性流程成功调价次数为0。
- [运行状态](status.json)：普通8203/PG5603五项健康；临时8403已停，私有配置摘要不变。未触碰06/07服务、3/11记录、数据库或素材。
- 既有29项/565断言固定1a49d048，完整浏览器29项固定a021c5e，契约59样例、依赖审计和迁移记录沿用[原版本证据](../f09-b01-b03/README.md)。本轮公共变更不改账务PHP/锁文件/迁移/冻结OpenAPI，不把旧结果改写为重跑通过。
- Stripe仍未配置/官方未验证；无正式收费、外部部署、广告发送或远程推送。

## 对照条件与图索引

唯一原稿仍为 `bc5a24d63b7e23126bad9bb5519ea0e9fa8825cf` 的index原始data-srcdoc，保留内部完整HTML/CSS/脚本，仅去掉外部iframe16px包装以匹配内部视口。原稿/导出文档摘要、浏览器版本、系统、视口、DPR1、100%缩放及浅色条件在[pairs.json](pairs.json)。原稿unpkg图标请求被隔离拦截，图标形状仍不判通过；应用外部请求0、脚本错误0、5xx为0。

| 场景 | 原稿 | 实际 | 范围 |
|---|---|---|---|
| 桌面价格 | [原图](e86227e-prices-desktop-approved.png) / [卡片](e86227e-prices-desktop-approved-card.png) | [原图](e86227e-prices-desktop-actual.png) / [卡片](e86227e-prices-desktop-actual-card.png) | 两侧同为13.34/4.21未保存输入；测试标记、版本、真实规则和预览说明为已记录扩展 |
| 窄屏价格 | [原图](e86227e-prices-narrow-approved.png) / [卡片](e86227e-prices-narrow-approved-card.png) | [原图](e86227e-prices-narrow-actual.png) / [卡片](e86227e-prices-narrow-actual-card.png) | 同值同状态，单列及底部动作 |
| 桌面订单入口 | [原图](e86227e-orders-desktop-approved.png) | [原图](e86227e-orders-desktop-actual.png) | 未选订单，实际近期订单表为扩展，不冒称同数据表格 |
| 窄屏订单入口 | [原图](e86227e-orders-narrow-approved.png) | [原图](e86227e-orders-narrow-actual.png) | 同上 |
| 桌面客服入口 | [原图](e86227e-support-desktop-approved.png) | [原图](e86227e-support-desktop-actual.png) | 未选订单，实际经办记录表为扩展 |
| 窄屏客服入口 | [原图](e86227e-support-narrow-approved.png) | [原图](e86227e-support-narrow-actual.png) | 同上 |

实际扩展/未配对：[桌面未保存失败弹窗](e86227e-unsaved-error-desktop-unpaired.png)、[短屏未保存失败弹窗](e86227e-unsaved-error-short-unpaired.png)、[非法价格](e86227e-prices-invalid-unpaired.png)、[订单详情](e86227e-order-selected-extension.png)、[客服详情](e86227e-support-selected-extension.png)。短屏图保留全页原始截图，实际可视窗口375×320，弹窗坐标见pairs.json；不能用整张长图高度代替视口高度。获批价格页本身未画同一未保存/失败状态，故不补造参考弹窗。暗色、不同缩放、全部失败/空库/未配置价格和所有扩展视觉状态仍未逐一配对。

[完整源码与21张图片摘要](source-manifest.json)、[验证结论](verification.json)。以上是03模块证据，是否设计接收及关闭F09仍由08/00/06决定。
