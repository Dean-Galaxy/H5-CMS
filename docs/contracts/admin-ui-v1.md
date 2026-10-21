# 员工后台公共组件 admin-ui-v1

本约定由 00 维护，依据用户在 08 对话批准的 UI v2 实施。业务契约仍为 **1.0.4**；本次只是内部显示与交互接口，不移动既有标签、不修改支付/会员/媒体 DTO。

## 文件与导航归属

00 独占 `h5-platform/app/Providers/Filament/AdminPanelProvider.php`、`resources/views/components/admin/`、`public/admin-ui/`、`app/Support/Admin/` 和 `tests/Feature/Contracts/AdminUiTest.php`。各模块不要复制一套同名实现。既有 `components/layouts/site.blade.php`、观众样式和 JS 保持由 04 维护。

保留 `<x-filament-panels::page>` 和原有员工面板、MFA、会话、导航注册、`canAccess()` 及动作中的服务器鉴权。00 在 **admin 面板** 的 head 注入带内容摘要版本的共享 CSS/JS；观众页面不加载。模块继续负责导航标题、顺序、角色可见性、方法权限及持久化。

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
