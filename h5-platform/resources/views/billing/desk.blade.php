<x-filament-panels::page>
    <style>
        .billing-desk {display:grid;gap:1.5rem}.billing-desk section {border:1px solid #94a3b8;border-radius:10px;padding:1rem;overflow:auto}.billing-desk h2{font-weight:700;font-size:1.1rem;margin-bottom:.75rem}.billing-desk p{margin:.5rem 0}.billing-desk form{display:flex;flex-wrap:wrap;align-items:end;gap:1rem;margin:.75rem 0}.billing-desk label{display:grid;gap:.3rem}.billing-desk input:not([type=checkbox]),.billing-desk textarea{border:1px solid #94a3b8;border-radius:5px;padding:.5rem;color:inherit;background:transparent}.billing-desk input[type=checkbox]{margin-right:.4rem}.billing-desk button{background:#1d4ed8;color:white;border-radius:5px;padding:.55rem .8rem}.billing-desk button:disabled{opacity:.45}.billing-desk table{width:100%;border-collapse:collapse;font-size:.9rem}.billing-desk th,.billing-desk td{text-align:left;padding:.5rem;border-bottom:1px solid #cbd5e1;vertical-align:top}.billing-desk .notice{border-left:4px solid #d97706;padding-left:.75rem}.billing-desk .mono{font-family:monospace;word-break:break-all}.billing-desk .check{display:block}.billing-desk .errors{color:#b91c1c}.billing-desk time{white-space:nowrap}
    </style>
    <div class="billing-desk">
        <p class="notice">{{ $stripe_configured ? 'Stripe 测试环境已配置；官方测试仍待核验' : 'Stripe 未配置；官方测试未验证' }}。</p>
        <p class="notice">美国 / USD / 全站周订阅测试工作台。当前支付账号未完成官方验收，不开放真实收费。金额、付款结果与会员权限由服务器核实。所有时间显示为北京时间。</p>
        @if ($errors->any())
            <div role="alert" class="errors">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif
        @if ($is_owner)
            <section>
                <h2>负责人：新订阅价格</h2>
                <p>版本：{{ $price['version'] }}。以下为美元最小单位整数：1200 = $12.00，300 = $3.00；仅是测试样例，尚非最终售价。首次保存前没有生效价格。</p>
                <form wire:submit="savePrice">
                    <label>常规周价（美分）<input wire:model="price.regular_amount_minor" type="number" min="1" max="99999999" required></label>
                    <label>首周优惠价（美分）<input wire:model="price.first_week_amount_minor" type="number" min="1" max="99999999" required></label>
                    <label class="check"><input wire:model="price.is_test" type="checkbox" required>明确保存为测试价格</label>
                    <button type="submit" wire:loading.attr="disabled">保存新的测试价格版本</button>
                </form>
                <p>调价只影响之后的新订阅。已有持续订阅及可恢复的原订阅保留原约定续费价；结束后再订阅用当前常规价，已用首周优惠不恢复。</p>
            </section>
        @endif
        <section>
            <h2>按用户提供的站内订单号处理问题</h2>
            <form wire:submit="openCase">
                <label>完整站内订单号<input wire:model="orderId" type="text" maxlength="36" placeholder="请输入用户提供的订单号" required></label>
                <button type="submit" wire:loading.attr="disabled">核对订单并打开客服记录</button>
            </form>
            <p>客服只查看自己经办的记录；每次查看、备注、身份核实和代取消均留审计。请勿在备注填写密码、支付凭据或完整银行卡资料。</p>
            @if ($cases)
                <table><thead><tr><th>站内订单</th><th>核实状态</th><th>建立时间</th><th></th></tr></thead><tbody>
                @foreach ($cases as $case)
                    <tr><td class="mono">{{ $case->order_id }}</td><td>{{ $case->identity_verified && $case->cancel_requested && $case->verified_at ? '身份与取消意愿已核实' : '尚未核实取消请求' }}</td><td>{{ \Carbon\CarbonImmutable::parse($case->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td><td><button type="button" wire:click="selectCase('{{ $case->id }}')" wire:loading.attr="disabled">打开</button></td></tr>
                @endforeach
                </tbody></table>
            @else
                <p>尚无客服记录。</p>
            @endif
        </section>
        @if ($selected)
            <section>
                <h2>订单与会员核实结果</h2>
                <p>站内订单：<span class="mono">{{ $selected['order']['id'] }}</span>；{{ $selected['order']['is_test'] ? '测试订单' : '正式订单' }}；订单状态：{{ $selected['order']['state'] }} / {{ $selected['order']['payment_state'] }}。</p>
                <p>当次约定 ${{ number_format($selected['order']['amount_minor'] / 100, 2) }} USD；原订阅约定周价 ${{ number_format($selected['order']['regular_amount_minor'] / 100, 2) }} USD；价格版本 {{ $selected['order']['price_version'] }}。</p>
                <p>当前会员：{{ $selected['membership']['access_active'] ? '有有效已付权益' : '当前无有效已付权益' }}；订阅：{{ $selected['membership']['subscription_state'] }}；未来续费：{{ $selected['membership']['renewal_enabled'] ? '已开启' : '已关闭' }}；{{ $selected['membership']['payment_resolution_required'] ? '仍需核对支付平台，不能当作已完成' : '暂无待核对标记' }}。</p>
                @if ($selected['membership']['access_until'])
                    <p>当前权益至 {{ \Carbon\CarbonImmutable::parse($selected['membership']['access_until'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}，不含到期时刻。</p>
                @endif
                <h2>已核实付款与对应周期</h2>
                <table><thead><tr><th>本站付款</th><th>已付 / 已退款 USD</th><th>实际周期（北京时间）</th></tr></thead><tbody>
                @forelse ($selected['payments'] as $payment)
                    <tr><td class="mono">{{ $payment->id }} {{ $payment->is_test ? '（测试）' : '' }}</td><td>${{ number_format($payment->amount_minor / 100, 2) }} / ${{ number_format($payment->refunded_amount_minor / 100, 2) }}</td><td>{{ \Carbon\CarbonImmutable::parse($payment->period_start)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }} → {{ \Carbon\CarbonImmutable::parse($payment->period_end)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td></tr>
                @empty
                    <tr><td colspan="3">暂无已核实付款。打开支付成功页不会自动增加会员权益。</td></tr>
                @endforelse
                </tbody></table>
            </section>
            <section>
                <h2>核实请求后的代取消</h2>
                <p>用户可直接在会员中心自助取消。需要代办时，先核实请求人身份及明确停止未来续费的意愿，再保存核实记录。核实记录仅供一次代取消使用，请求受理后即失效；平台处理中由系统核对原操作。再次代办须重新核实用户当前意愿，不能沿用旧请求取消用户后来主动恢复的续费。</p>
                <form wire:submit="saveVerification">
                    <label class="check"><input wire:model="identityVerified" type="checkbox">已核实是该订单所属账号的用户</label>
                    <label class="check"><input wire:model="cancelRequested" type="checkbox">已收到该用户明确取消自动续费的请求</label>
                    <button type="submit" wire:loading.attr="disabled">保存本次核实记录</button>
                </form>
                <button type="button" wire:click="cancelForCustomer" wire:loading.attr="disabled" @disabled(! $selected['case']['identity_verified'] || ! $selected['case']['cancel_requested'] || ! $selected['case']['verified_at'])>代用户停止未来续费</button>
                <p>平台处理未核实前显示处理中。已付权益保留至原到期，本次取消不自动退款；依法享有的退款、撤回及其他权利不受影响。客服不能直接退款、加时长、更改付款结果或恢复续费。</p>
            </section>
            <section>
                <h2>处理备注</h2>
                <form wire:submit="addNote">
                    <label>本次处理说明<textarea wire:model="note" rows="3" maxlength="2000" required></textarea></label>
                    <button type="submit" wire:loading.attr="disabled">添加备注</button>
                </form>
                @forelse ($selected['notes'] as $entry)
                    <p><time>{{ \Carbon\CarbonImmutable::parse($entry->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</time>：{{ $entry->body }}</p>
                @empty
                    <p>尚无备注。</p>
                @endforelse
            </section>
        @endif
        @if ($is_owner)
            <section>
                <h2>负责人：最近 50 笔站内订单</h2>
                <table><thead><tr><th>订单号</th><th>金额 USD</th><th>状态</th><th>价格版本</th></tr></thead><tbody>
                @forelse ($orders as $order)
                    <tr><td class="mono">{{ $order->id }} {{ $order->is_test ? '（测试）' : '' }}</td><td>${{ number_format($order->amount_minor / 100, 2) }}</td><td>{{ $order->state }} / {{ $order->payment_state }}</td><td>{{ $order->price_version }}</td></tr>
                @empty
                    <tr><td colspan="4">尚无订单。</td></tr>
                @endforelse
                </tbody></table>
            </section>
        @endif
    </div>
</x-filament-panels::page>
