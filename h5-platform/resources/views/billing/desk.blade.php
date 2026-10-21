<x-filament-panels::page>
    <x-admin.workspace id="billing-workspace" title="订单与会员" description="测试环境 · 全部金额以美元（USD）展示">
        <x-slot:actions><span class="h5-badge h5-warn" role="status">{{ $stripe_configured ? 'Stripe 测试配置齐备；官方测试未验证' : 'Stripe 未配置；官方测试未验证' }} · 不开放真实收费</span></x-slot:actions>
        <div class="billing-workspace" data-billing-workspace>
            <style>
                .billing-workspace{display:grid;gap:1.25rem}.billing-workspace>.h5-tabs{margin-bottom:2px}.billing-workspace p{margin:.5rem 0}.billing-workspace h3{margin-top:1rem}.billing-workspace .billing-form{display:flex;align-items:end;flex-wrap:wrap;gap:1rem;margin:1rem 0}.billing-workspace .billing-form>.h5-field{flex:1 1 16rem}.billing-workspace .billing-check{display:flex;align-items:start;gap:.55rem}.billing-workspace .billing-check input{margin-top:.2rem}.billing-workspace .billing-id{font-family:monospace;overflow-wrap:anywhere}.billing-workspace .billing-amount{font-variant-numeric:tabular-nums;white-space:nowrap}.billing-workspace .billing-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin:1rem 0}.billing-workspace .billing-facts dt{font-size:.85rem;color:var(--h5-muted)}.billing-workspace .billing-facts dd{margin-top:.25rem;overflow-wrap:anywhere}.billing-workspace .billing-stack{display:grid;gap:1rem}.billing-workspace .billing-note{border-top:1px solid var(--h5-line);padding-top:.8rem;white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:640px){.billing-workspace .billing-form{align-items:stretch}.billing-workspace .billing-form button{width:100%}.billing-workspace .billing-facts{grid-template-columns:1fr}}
                .billing-workspace .billing-price-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:18px}.billing-workspace .billing-price-head p{margin:0}.billing-workspace .billing-money{position:relative}.billing-workspace .billing-money>span{position:absolute;left:12px;top:10px;color:var(--h5-muted)}.billing-workspace .billing-money input{padding-left:28px}.billing-workspace .billing-price-feedback{min-height:20px;margin-top:10px}.billing-workspace .billing-price-preview{padding:18px 0}.billing-workspace .billing-price-preview h3{margin:0}.billing-workspace .billing-price-preview p{margin:7px 0 0}.billing-workspace .billing-price-actions{border-top:1px solid var(--h5-line);margin-top:22px;padding-top:17px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.billing-workspace .billing-price-rules{font-size:12px}.billing-workspace .billing-price-actions .billing-check{font-size:12px;color:var(--h5-muted)}.billing-workspace .billing-query{max-width:245px}.billing-workspace .billing-query input{width:100%}
            </style>
            <x-admin.tabs id="billing-tabs" :items="$tabs" :active="$activeTab" method="selectTab" />
            @if ($errors->any() && $activeTab !== 'prices')
                <div role="alert" class="h5-error">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <p data-billing-navigation-error role="alert" class="h5-error" hidden></p>

            @if ($activeTab === 'orders')
                <x-admin.panel group="billing-tabs" name="orders" :active="$activeTab">
                    <section class="h5-card">
                        <h2>查询订单</h2>
                        <p class="h5-muted">输入用户提供的完整站内订单号，查询会建立或打开本人的客服记录。</p>
                        <form class="billing-form" data-billing-query>
                            <label class="h5-field billing-query" for="billing-order-id">完整站内订单号<input class="h5-input" id="billing-order-id" wire:model="orderId" type="text" maxlength="36" placeholder="请输入完整订单号" required></label>
                            <button class="h5-button h5-primary" type="submit" wire:loading.attr="disabled">查询订单</button>
                        </form>
                    </section>
                    @if ($selected)
                        @include('billing.partials.order-detail')
                        <div><button class="h5-button h5-primary" type="button" data-billing-tab="support" wire:loading.attr="disabled">进入此订单的客服处理</button></div>
                    @endif
                    @if ($is_owner)
                        <section class="h5-card">
                            <h2>最近 50 笔站内订单</h2>
                            <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>订单号</th><th>订单约定金额</th><th>状态</th><th>价格版本</th><th>操作</th></tr></thead><tbody>
                                @forelse ($orders as $order)
                                    <tr wire:key="billing-order-{{ $order->id }}"><td class="billing-id">{{ $order->id }}<p class="h5-muted">{{ $order->is_test ? '测试订单' : '正式订单' }}</p></td><td class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($order->amount_minor) }}</td><td>{{ $order->state }} / {{ $order->payment_state }}</td><td>{{ $order->price_version }}</td><td><button class="h5-link" type="button" data-billing-order="{{ $order->id }}" wire:loading.attr="disabled">查看</button></td></tr>
                                @empty
                                    <tr><td colspan="5">尚无订单。</td></tr>
                                @endforelse
                            </tbody></table></div>
                        </section>
                    @endif
                </x-admin.panel>
            @elseif ($activeTab === 'prices' && $is_owner)
                <x-admin.panel group="billing-tabs" name="prices" :active="$activeTab">
                <form class="h5-card" wire:submit="savePrice" data-billing-pricing
                    x-data="{
                        amount(value) {
                            if (typeof value !== 'string' || !/^[0-9]+(\.[0-9]{1,2})?$/.test(value)) return null;
                            const parts = value.split('.');
                            const whole = parts[0].replace(/^0+/, '') || '0';
                            if (whole.length > 6) return null;
                            const cents = Number(whole) * 100 + Number((parts[1] || '').padEnd(2, '0'));
                            return cents > 0 &amp;&amp; cents &lt;= 99999999 ? { cents, text: whole + '.' + (parts[1] || '').padEnd(2, '0') } : null;
                        },
                        get preview() {
                            const regular = this.amount($wire.price.regular_amount_usd);
                            const first = this.amount($wire.price.first_week_amount_usd);
                            return regular &amp;&amp; first &amp;&amp; first.cents &lt;= regular.cents
                                ? '首周 $' + first.text + '，之后 $' + regular.text + ' / 周'
                                : '请输入有效美元金额；首周价不能高于常规价。';
                        }
                    }">
                    <div class="billing-price-head">
                        <div><h2>全站周会员</h2><p class="h5-muted billing-price-rules">测试价格 · 尚非正式售价 · 当前版本 {{ $price['version'] }}</p></div>
                        <span class="h5-badge">每周自动续费</span>
                    </div>
                    <div class="h5-form-grid billing-price-fields">
                        <label class="h5-field" for="billing-regular-usd">常规周价（USD）<div class="billing-money"><span aria-hidden="true">$</span><input class="h5-input" id="billing-regular-usd" wire:model="price.regular_amount_usd" type="text" inputmode="decimal" maxlength="32" autocomplete="off" required></div></label>
                        <label class="h5-field" for="billing-first-week-usd">首周优惠价（USD）<div class="billing-money"><span aria-hidden="true">$</span><input class="h5-input" id="billing-first-week-usd" wire:model="price.first_week_amount_usd" type="text" inputmode="decimal" maxlength="32" autocomplete="off" required></div></label>
                    </div>
                    <div class="billing-price-feedback">
                        @if ($errors->any())<div class="h5-error" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                    </div>
                    <div class="billing-price-preview">
                        <h3>订阅页将这样展示</h3>
                        <p data-billing-price-preview aria-live="polite" x-text="preview">请输入有效美元金额。</p>
                        <p class="h5-muted billing-price-rules">输入预览，不代表已保存或服务端报价。{{ $price['version'] ? '' : '尚未配置生效价格。' }}</p>
                    </div>
                    <p class="h5-muted billing-price-rules">新价格适用于后续新订阅；已有持续订阅及可恢复的原订阅按原约定价格续费。结束后再订阅用当前常规价，已用首周优惠不恢复。</p>
                    <div class="billing-price-actions">
                        <div><span class="h5-muted billing-price-rules">最多两位小数，至少 $0.01 USD</span><label class="billing-check"><input wire:model="price.is_test" type="checkbox" required>明确保存为测试价格</label></div>
                        <button class="h5-button h5-primary" type="submit" wire:loading.attr="disabled">保存测试价格</button>
                    </div>
                </form>
                </x-admin.panel>
            @elseif ($activeTab === 'support')
                <x-admin.panel group="billing-tabs" name="support" :active="$activeTab">
                    <section class="h5-card">
                        <h2>客服记录</h2>
                        <p class="h5-muted">查询订单后建立记录，备注、核实身份与代取消续费在该订单内完成。</p>
                        <p class="h5-muted">{{ $is_owner ? '负责人可查看全部经办记录。' : '仅显示自己经办的记录。' }}每次查看、备注、核实及代取消均留审计。</p>
                        <button class="h5-button" type="button" data-billing-tab="orders">按订单号查询</button>
                        <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>站内订单</th><th>核实状态</th><th>建立时间</th><th>操作</th></tr></thead><tbody>
                            @forelse ($cases as $case)
                                <tr wire:key="billing-case-{{ $case->id }}"><td class="billing-id">{{ $case->order_id }}</td><td>{{ $case->identity_verified && $case->cancel_requested && $case->verified_at ? '身份与取消意愿已核实' : '尚未核实取消请求' }}</td><td>{{ \Carbon\CarbonImmutable::parse($case->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td><td><button class="h5-link" type="button" data-billing-case="{{ $case->id }}" wire:loading.attr="disabled">打开</button></td></tr>
                            @empty
                                <tr><td colspan="4">尚无客服记录。请先按用户提供的完整订单号查询。</td></tr>
                            @endforelse
                        </tbody></table></div>
                    </section>
                    @if ($selected)
                        @include('billing.partials.order-detail')
                        <section class="h5-card">
                            <h2>核实请求后的代取消</h2>
                            <p class="h5-muted">用户可在会员中心自助取消。代办前须核实身份和当前取消意愿；核实记录仅使用一次。平台处理中由系统核对原操作，再次代办须重新核实。</p>
                            <form class="billing-stack" wire:submit="saveVerification">
                                <label class="billing-check"><input wire:model="identityVerified" type="checkbox">已核实是该订单所属账号的用户</label>
                                <label class="billing-check"><input wire:model="cancelRequested" type="checkbox">已收到该用户明确取消自动续费的请求</label>
                                <div><button class="h5-button" type="submit" wire:loading.attr="disabled">保存本次核实记录</button></div>
                            </form>
                            <p><button class="h5-button h5-primary" type="button" data-billing-cancel wire:loading.attr="disabled" @disabled(! $selected['case']['identity_verified'] || ! $selected['case']['cancel_requested'] || ! $selected['case']['verified_at'])>代用户停止未来续费</button></p>
                            <p class="h5-muted">已付权益保留至原到期，本次取消不自动退款；依法享有的退款、撤回及其他权利不受影响。客服不能直接退款、加时长、更改付款结果或恢复续费。</p>
                        </section>
                        <section class="h5-card">
                            <h2>处理备注</h2>
                            <p class="h5-muted">请勿填写密码、支付凭据或完整银行卡资料。</p>
                            <form class="billing-form" wire:submit="addNote">
                                <label class="h5-field" for="billing-note">本次处理说明<textarea class="h5-input" id="billing-note" wire:model="note" rows="3" maxlength="2000" required></textarea></label>
                                <button class="h5-button h5-primary" type="submit" wire:loading.attr="disabled">添加备注</button>
                            </form>
                            @forelse ($selected['notes'] as $entry)
                                <div class="billing-note" wire:key="billing-note-{{ $entry->id }}"><time class="h5-muted">{{ \Carbon\CarbonImmutable::parse($entry->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</time><p>{{ $entry->body }}</p></div>
                            @empty
                                <p class="h5-muted">尚无备注。</p>
                            @endforelse
                        </section>
                    @endif
                </x-admin.panel>
            @endif
        </div>
    </x-admin.workspace>
</x-filament-panels::page>

@script
<script>
    const billingRoot = $wire.$el;
    let billingBusy = false;
    let billingDispose = null;
    const billingDirty = () => {
        const price = $wire.$get('price');
        const saved = $wire.$get('savedPrice');
        const verification = $wire.$get('savedVerification');
        return ['version', 'regular_amount_usd', 'first_week_amount_usd', 'is_test'].some(key => price[key] !== saved[key])
            || $wire.$get('note') !== ''
            || $wire.$get('identityVerified') !== verification.identity_verified
            || $wire.$get('cancelRequested') !== verification.cancel_requested;
    };
    const billingCallbacks = {
        isDirty: billingDirty,
        save: async () => (await $wire.savePending()) === true,
        discard: async () => (await $wire.discardPending()) === true,
    };
    const billingProtect = () => {
        if (!billingRoot.isConnected || !window.H5Admin?.protect) return;
        billingDispose = window.H5Admin.protect(billingRoot, billingCallbacks);
    };
    if (window.H5Admin?.protect) billingProtect();
    else document.addEventListener('h5-admin:ready', billingProtect, { once: true });
    const billingCleanup = () => {
        if (billingRoot.isConnected) return;
        billingDispose?.();
        document.removeEventListener('h5-admin:ready', billingProtect);
        document.removeEventListener('livewire:navigated', billingCleanup);
    };
    document.addEventListener('livewire:navigated', billingCleanup);

    // These change the selected Billing object; shared protect owns tabs and page navigation.
    const billingNavigate = async (action) => {
        if (billingBusy) return false;
        billingBusy = true;
        const error = billingRoot.querySelector('[data-billing-navigation-error]');
        if (error) error.hidden = true;
        try {
            if (billingDirty()) {
                if (!window.H5Admin?.confirmUnsaved) throw new Error('shared-dialog-unavailable');
                if ((await window.H5Admin.confirmUnsaved(billingCallbacks)) !== true) return false;
            }
            return (await action()) === true;
        } catch (_) {
            if (error) {
                error.textContent = '操作未完成，已保留当前输入。请稍后重试。';
                error.hidden = false;
            }
            return false;
        } finally {
            billingBusy = false;
        }
    };
    billingRoot.addEventListener('submit', event => {
        if (!event.target.matches('[data-billing-query]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        billingNavigate(() => $wire.openCase());
    }, true);
    billingRoot.addEventListener('click', event => {
        const button = event.target.closest('[data-billing-order], [data-billing-case], [data-billing-tab], [data-billing-cancel]');
        if (!button || button.disabled) return;
        event.preventDefault();
        if (button.hasAttribute('data-billing-order')) billingNavigate(() => $wire.openCase(button.dataset.billingOrder));
        else if (button.hasAttribute('data-billing-case')) billingNavigate(() => $wire.selectCase(button.dataset.billingCase));
        else if (button.hasAttribute('data-billing-tab')) billingNavigate(() => $wire.selectTab(button.dataset.billingTab));
        else billingNavigate(() => $wire.cancelForCustomer());
    });
</script>
@endscript
