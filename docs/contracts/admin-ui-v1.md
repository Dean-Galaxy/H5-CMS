# 员工后台公共组件 admin-ui-v1

## 设计一致性强制接收补充（2026-10-21）

08已批准归档中的页面结构、操作流程和视觉层级是实施基准，不能仅取标签/功能后自行另排界面。尤其批量上传分步弹窗、分集表格/筛选与编辑弹窗不得退化为常驻长表单、details或window.prompt。模块不得用旧高优先级CSS覆盖公共颜色/控件令牌；必要适配逐项记录。原稿示例数据/模拟成功不进入真实实现，后端权限及业务事实保持。

交付增加同视口/同状态的原型与真实截图配对、状态及操作步骤映射。功能测试、无横向溢出、组件存在不能代替设计对照。原稿没给出的画面不编造像素要求；新的产品决定才需用户确认。当前F09未关闭，详见[00-23](../handoffs/00-23-设计一致性退回与纠偏.md)。本补充不改变业务契约1.0.5或现有组件调用接口。

本约定由 00 维护，依据用户在 08 对话批准的 UI v2 实施。初始组件基于1.0.4；员工密码声明接线后公共契约为 **1.0.5**，内部UI接口仍为admin-ui-v1，不移动既有标签、不修改支付/会员/媒体 DTO。

## 文件与导航归属

00 独占 `h5-platform/app/Providers/Filament/AdminPanelProvider.php`、`resources/views/components/admin/`、`public/admin-ui/`、`app/Support/Admin/` 和 `tests/Feature/Contracts/AdminUiTest.php`。各模块不要复制一套同名实现。既有 `components/layouts/site.blade.php`、观众样式和 JS 保持由 04 维护。

保留 `<x-filament-panels::page>` 和原有员工面板、MFA、会话、导航注册、`canAccess()` 及动作中的服务器鉴权。00 在 **admin 面板** 的 head 注入带内容摘要版本的共享 CSS/JS；观众页面不加载。00统一已批准的导航标题、顺序与图标；模块继续负责角色可见性、方法权限及持久化。

## 结构和样式

```blade
<x-filament-panels::page>
    <x-admin.workspace id="billing-workspace" title="订单与会员" description="查询订单，管理价格和客服记录">
        <x-slot:actions><button class="h5-button" type="button">刷新</button></x-slot:actions>
        <x-admin.tabs id="billing-tabs" :items="$visibleTabs" :active="$tab" method="selectTab" />
        <x-admin.panel group="billing-tabs" name="orders" :active="$tab">
            <!-- 仅渲染已授权的真实业务数据 -->
        </x-admin.panel>
    </x-admin.workspace>
</x-filament-panels::page>
```

- `workspace`：可选 `title`、`description` 和 `actions` 插槽；通用属性原样传递，模块可带 `wire:ignore`。不要在共享壳显示原型剧名、固定日期、价格或成功动画。
- `tabs`：必填唯一 `id`，`items` 为 `[['id'=>'orders','label'=>'订单查询','count'=>2], ...]`；`count` 可省略。`active` 必须是当前可见项，初始无效值回退首项。模块状态也必须验证白名单，隐藏标签不等于授权。
- `method` 是可选的 **本页 Livewire 方法名**（例如 `selectTab`），不接受表达式。带 method 时调用 `selectTab('orders')`，等待服务器重新渲染，不抢先假装成功；方法应核验 tab 白名单。无 method 为纯前端模式，会显示对应 `panel`，派发 `h5-admin:tab-change`。
- Livewire加载时标签使用`aria-disabled=true`，公共事件处理阻止重复点击和方向键动作，保留原焦点；不要改回原生`disabled`，否则Chrome会把焦点移到BODY。服务器响应不执行强制focus，因此不抢其他输入或弹窗的焦点，拒绝切换时也不伪造选中状态。真实Livewire HTTP夹具见Contracts/Support中的`admin-ui-livewire-*`。
- `panel`：`group` 对应标签组 id、`name` 对应项目 id、`active` 是当前激活的 **字符串**；只显示匹配项。键盘左右键/Home/End可切换，使用标准 tab/tabpanel/aria 关系。嵌套标签组必须各有不同 id。
- 模块维护 URL/返回上下文。Livewire 可用自己的 URL 属性；纯前端可监听 `tab-change` 按业务更新地址，浏览器返回也必须走未保存检查。
- 公共样式类：`h5-card`、`h5-toolbar`、`h5-button h5-primary`、`h5-button h5-danger`、`h5-form-grid`、`h5-field`、`h5-input`、`h5-span`、`h5-table-wrap`/`h5-table`、`h5-badge`、`h5-alert`/`h5-error`/`h5-success`、`h5-muted`。都限于公共 workspace/dialog 内；窄屏表格横向滚动、表单双列改单列。不要改观众 CSS。

## 弹窗

```blade
<x-admin.dialog id="edit-episode" title="编辑分集">
    <form id="episode-editor"><!-- 真实表单 --></form>
    <x-slot:actions><button type="submit" form="episode-editor" class="h5-button h5-primary">保存</button></x-slot:actions>
</x-admin.dialog>
```

`H5Admin.openDialog('edit-episode')` / `closeDialog('edit-episode')` 返回是否找到有效 dialog。原生 modal 控制焦点和键盘，正文独立滚动、按钮保留在可见区域。不点击遮罩自动丢弃表单。

关闭按钮和 Escape 会先派发可取消的 `h5-admin:dialog-close-request`，`detail={dialog}`。模块可以阻止关闭并确认；确认后调用 `closeDialog` **直接关闭，不再派发请求事件**。使用 module-owned `close` 监听器清理候选状态；不要将关闭当作服务器保存。

## 未保存内容：保存成功才能继续

`H5Admin.confirmUnsaved({save, discard})` 返回 `Promise<boolean>`；三个选项为“保存并继续 / 放弃修改 / 继续编辑”。

- `save` / `discard` 必须是实际业务回调，支持 async，**只有明确返回 `true` 才允许继续**。`undefined`、`false`、抛错、网络失败、服务端校验失败都留在编辑界面并显示提示。无回调时对应选项禁用。
- `save` 在服务器成功后清除自己的脏状态；不要在 submit、请求发出或 loading 结束时先清除。Livewire 方法默认返回 void 的，要显式区分成功/失败。
- `discard` 必须恢复到最近一次服务器确认的数据，并重置相应 Livewire 模型/候选状态后返回 true；只执行 HTML `form.reset()` 无法保证 Livewire 内部值还原。若输入仍有未完成上传，不得仅返回 true 假装取消完成。
- 正在保存/放弃时按钮和 Escape 暂停，避免重复操作；失败后可重试或继续编辑。第二个并发离开请求返回 false，不共享上一请求的授权。

快速接入：

```javascript
const dispose = H5Admin.protect(document.getElementById('billing-workspace'), {
    isDirty: () => hasUnconfirmedChanges,
    save: async () => { /* 调用真实保存，成功后清脏并返回 true */ },
    discard: async () => { /* 还原真实表单模型，成功后清脏并返回 true */ },
});
// 页面销毁时 dispose()；重复对同一 root 调用 protect 会替换旧监听。
```

`protect` 统一处理 root 内标签/弹窗关闭、员工面板链接离开和 beforeunload。刷新、关页和浏览器级离开只能显示浏览器原生确认框（浏览器可能限制文案），不能在关页时异步保存。模块自己的剧目切换、列表切换、URL history/popstate等仍须调用 `confirmUnsaved` 后再改状态；不可把保护范围扩大成后台业务接口授权。

需要自己接 tab 时：监听 root 的 `h5-admin:tab-request`（bubbles/cancelable）。`detail={id,group,button,select}`；脏状态下同步 `preventDefault()`，再 await 确认，成功后 `detail.select()` 或 `H5Admin.activateTab(button)`。**activateTab 只跳过本次公共请求事件一次，不递归弹窗**，仍执行真实 Livewire 方法或前端切换；不可直接篡改服务器状态。若已用 protect 则不用另写重复监听。

资源使用 defer；延迟挂载的代码可监听 `h5-admin:ready`，或先判断 `window.H5Admin`。公共脚本不写 localStorage、不保存表单值、不采集密码/个人信息。

## 美元辅助：只在输入展示边界转换

`App\Support\Admin\UsdAmount`：

| 方法 | 行为 |
|---|---|
| `fromDecimal(mixed $value): int` | 仅接受字符串，例如 `'12'`/`'12.00'`→1200、`'0.01'`→1、`'-0.01'`→-1；允许前导零，不用 float 运算 |
| `toDecimal(int $minor): string` | 1200→`'12.00'`，回填输入控件；保留负数 |
| `format(int $minor): string` | 1200→`'$12.00 USD'`，-1→`'-$0.01 USD'`；只读实际金额不丢失 |

非法类型（包括 int/float）、空白、逗号、指数、多于两位小数、空值、整数范围溢出抛出 **`\InvalidArgumentException`**。输入控件使用字符串，最多两位小数；不要先 `(float)` 或 `parseFloat` 再交服务器。捕获错误后转成模块字段校验提示。

业务限制仍属模块：03 常规和首周价格最小 0.01、最大 999999.99 USD，首周不高于常规；05 的真实汇总字段是整数，使用格式化即可。负数/零值能否接受由字段控制，formatter 不隐瞒只读负数。旧内部表单字段、DTO、账本、SDK、事件仍是 USD 最小单位整数，不因 UI 切换换算第二遍。不开 CSV、新报表、默认测试价格或官方支付。

## 接收标准

00 校验公共金额边界、组件渲染/转义、员工独占资源和浏览器交互，再发布固定可接入提交。模块在此提交上交付自己的真实界面与必要测试；06 在 00 的最终组合 SHA 上复验 UI01–UI11。共享组件通过不能替代员工 8 位密码、媒体替换、发布排期或真实支付验收。


## F09 公共接入修正（2026-10-21；不是整版设计验收）

以08固定审查 `docs/ui/08-admin-v2/design-fidelity-review.md` 为准。公共原型仍bc5a24d，业务契约1.0.5不变。

- workspace 的 `title` 输出唯一 `h1.h5-workspace-title`，description紧邻，actions位于同一标题行；有此主标题时公共样式隐藏外层重复Filament标题。模块不要另外再加主标题。
- 新增 `h5-link` 文字动作；`h5-badge h5-ok/h5-warn/h5-bad` 状态。旧类继续兼容。禁止用模块ID选择器覆盖共同颜色、字号及弹窗宽度。
- 弹窗（含未保存确认）统一640px含边框、24px内距、顶部62px、无头底分隔线、X关闭；580px及以下内距17px/顶部18px。真实原生dialog继续限高、正文滚动、底部操作可达。未保存状态和成功判定语义不变。
- `StaffNavigation`只给已注册且模块canAccess允许的页面建立固定顺序；关闭投流模块不会为了凑齐原型而显示。全局设置等待02的ContentSettings独立页，仍仅负责人可用。原 `/admin` Dashboard 保留兼容直达与框架首页链接，但从业务模块导航移除；不是新增的第六个模块。
- 侧栏164px；800px以下同一导航转横排；不另加第二套壳。金额/时间的侧栏说明显示真实角色。本机环境提示简短展示，模块仍要保留与动作相关的未联调、失败及结果未知信息。
- 公共截图目前仅合成角色的服务器HTML渲染与组件夹具：可证明布局/交互检查，不代表所有真实业务状态已与原稿配对。模块集成后的标题、弹窗长内容和错误回显仍需06在固定候选复验。F09未关闭，07不恢复。


F09补充：公共 `h5-choice`（label包裹checkbox/radio）与 `h5-note` 已按原稿P:65–68提供；选择行12px内距/10px上距、选中软背景，短注12px/9px上距。模块使用这些类恢复原稿语义层级，不复制CSS。原稿管理窗角色字段上距18px、新增表单上距20px属于对应内容布局；公共标题间距尚待同状态对照，不以单页4px差值直接改变所有弹窗。


08复核补充：公共标题不再设置37px最小高度，18px字号/1.55自然行高后5px间隔；关闭图标按钮使用通用8px/13px内距和37px桌面最小高度（580px以下42px）。dialog-body不再隐式grid/gap16px，内容按各原稿状态显式布局，仍保留限高滚动。workspace标题→下一行、标签→面板使用22px间隔，其余行维持原有间隔。请在本次公共补丁后再做最终配对，不给模块补偿旧37px/gap16px的反向margin。


### F09原型浏览器实测后的正文起距（取代上述仅源码推导）

08在固定index.html实测：导出宿主令h2行高1.4；原型标题下5px与首块18/20px外距合并，不能相加。公共弹窗现显式line-height:1.4，header无底内距；`<x-admin.dialog content-start-gap="20">`用正文顶部内距表达 `max(5, 首块起距)`，默认5。新增员工20、管理员工18，模块**移除首块原有重复margin-top**；其他弹窗根据自己的原稿首项设置，不统一20。原生滚动、焦点和footer可达保持。原生JS生成dialog可设置同名CSS变量 `--h5-dialog-content-start-gap`；不使用负margin补偿。

关闭按钮图标按固定index实测16px，通用8px/13px内距得到桌面44×37、窄屏44×42。首字段位置需按弹窗边框相对坐标比对；08先前“约9.1px”和01“约4px”不作为全局修正量。原型长表单全窗高度、图标、真实错误等仍分别配对，不据这四组控件起点测量宣布全部通过。


F09完整导出排版补齐：固定index.html内原始data-srcdoc还规定h1/h2/h3行高分别1.3333333333/1.4/1.5555555556。公共workspace及dialog普通标题显式继承这些规则，不能只读approved-fragment而忽略其宿主默认排版。由此主标题后整体位置和价格卡h2后位置会变化，相关页面须更新实际配对图；原有业务测试结果仍按版本保留，不重跑无关业务。不得把原型外层16px导出留白复制进应用。


### F09公共安全与完整排版收敛

- 长内容真实适配：header为X按钮保留完整安全高度；H5Admin按真实标题/按钮尺寸计算超出高度，正文实际起距=`max(0,声明起距−超出高度)`。18/20px首字段位置不变，默认5px在窄屏必要时让到X下。正文滚动边界始终在X下方；长标题、窗口变化、Livewire标题变化会重算，关闭时释放观察器。不得用反向margin让滚动正文重新进入header。
- 完整data-srcdoc字体栈已用于h1/h2/h3/p；small为12px/18px，b/strong600，徽标不换行，disabled透明度.45；关闭与导航图标尺寸16px/线宽1.6。图标形状与原稿是否一致仍需单独对照，不靠尺寸掩盖。
- 表格紧跟短注时用`<div class="h5-table-with-note">表格容器 + p.h5-note</div>`作为panel的一项，得到原稿9px距离；不要让panel的18px网格间隔再叠上9px，也不全局削减其他panel行距。
- 05报告的X覆盖长说明/滚动正文是明确缺陷，修正前证据不作设计通过。新公共回归增加5项：桌面20px保持、短屏首行安全、滚动边界安全、窄屏18px保持、长标题起距保持；原17项继续保留。
