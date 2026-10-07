@php
    use App\Modules\Audience\Billing\BillingText as B;
@endphp
@if($membership->payment_resolution_required)<p role="status" class="billing-notice">{{ B::get('pending') }}</p><p>{{ B::get('renewal_pending') }}</p>@endif
<p class="badge">{{ B::get($membership->access_active ? 'active' : 'inactive') }}</p>
<dl class="billing-facts">
    <div><dt>{{ B::get('membership') }}</dt><dd>{{ B::get('state_'.$membership->subscription_state->value) }}</dd></div>
    <div><dt>{{ B::get('until') }}</dt><dd><time datetime="{{ $membership->access_until }}">{{ B::date($membership->access_until) }}</time></dd></div>
    @if($membership->renewal_enabled)
    <div><dt>{{ B::get('renewal') }}</dt><dd>{{ $membership->next_renewal_amount_minor !== null ? B::money($membership->next_renewal_amount_minor, 'USD') : '—' }}<br><time datetime="{{ $membership->next_renewal_at }}">{{ B::date($membership->next_renewal_at) }}</time></dd></div>
    @elseif(!$membership->payment_resolution_required)
    <div><dt>{{ B::get('renewal') }}</dt><dd>{{ B::get('no_renewal') }}</dd></div>
    @endif
</dl>
