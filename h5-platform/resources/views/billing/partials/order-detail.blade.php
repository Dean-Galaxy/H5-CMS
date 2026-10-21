<section class="h5-card">
    <h2>订单与会员核实结果</h2>
    <p class="billing-id">{{ $selected['order']['id'] }}</p>
    <p class="h5-muted">{{ $selected['order']['is_test'] ? '测试订单' : '正式订单' }} · {{ $selected['order']['state'] }} / {{ $selected['order']['payment_state'] }} · 价格版本 {{ $selected['order']['price_version'] }}</p>
    <dl class="billing-facts">
        <div><dt>当次约定金额</dt><dd class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($selected['order']['amount_minor']) }}</dd></div>
        <div><dt>原订阅约定周价</dt><dd class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($selected['order']['regular_amount_minor']) }}</dd></div>
        <div><dt>当前已付权益</dt><dd>{{ $selected['membership']['access_active'] ? '有效' : '当前无有效已付权益' }}</dd></div>
        <div><dt>当前订阅状态</dt><dd>{{ $selected['membership']['subscription_state'] }}</dd></div>
        <div><dt>未来续费</dt><dd>{{ $selected['membership']['renewal_enabled'] ? '已开启' : '已关闭' }}</dd></div>
        @if ($selected['membership']['access_until'])
            <div><dt>当前权益到期时间（不含该时刻）</dt><dd>{{ \Carbon\CarbonImmutable::parse($selected['membership']['access_until'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</dd></div>
        @endif
        @if ($selected['membership']['next_renewal_amount_minor'] !== null)
            <div><dt>当前订阅下次约定续费金额</dt><dd class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($selected['membership']['next_renewal_amount_minor']) }}</dd></div>
        @endif
        @if ($selected['membership']['next_renewal_at'])
            <div><dt>下次续费时间</dt><dd>{{ \Carbon\CarbonImmutable::parse($selected['membership']['next_renewal_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</dd></div>
        @endif
    </dl>
    @if ($selected['membership']['payment_resolution_required'])
        <p class="h5-alert">支付平台状态仍待核对，不能当作操作已完成。</p>
    @endif
    <h3>已核实付款与对应周期</h3>
    <p class="h5-muted">显示该订单关联订阅最近 30 笔付款；会员状态反映此账号当前权益。</p>
    <div class="h5-table-wrap"><table class="h5-table"><thead><tr><th>本站付款</th><th>已付金额</th><th>实际已退款金额</th><th>实际周期（北京时间）</th></tr></thead><tbody>
        @forelse ($selected['payments'] as $payment)
            <tr wire:key="billing-payment-{{ $payment->id }}"><td class="billing-id">{{ $payment->id }}<p class="h5-muted">{{ $payment->is_test ? '测试付款' : '正式付款' }}</p></td><td class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($payment->amount_minor) }}</td><td class="billing-amount">{{ \App\Support\Admin\UsdAmount::format($payment->refunded_amount_minor) }}</td><td>{{ \Carbon\CarbonImmutable::parse($payment->period_start)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}<br>至 {{ \Carbon\CarbonImmutable::parse($payment->period_end)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}（不含）</td></tr>
        @empty
            <tr><td colspan="4">暂无已核实付款。打开支付成功页不会自动增加会员权益。</td></tr>
        @endforelse
    </tbody></table></div>
</section>
