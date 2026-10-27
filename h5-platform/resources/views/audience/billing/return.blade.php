@php use App\Modules\Audience\Billing\BillingText as B; @endphp
<x-layouts.site :title="B::get('payment')">
@include('audience.billing.assets')
<section class="card billing-panel">
    <h1>{{ B::get('payment') }}</h1><p>{{ B::get('return_note') }}</p>
    <dl class="billing-facts">
        <div><dt>{{ B::get('order') }}</dt><dd class="billing-id">{{ $order->order_id }}</dd></div>
        <div><dt>{{ B::get('paid_amount') }}</dt><dd>{{ B::money($order->amount_minor, $order->currency) }}</dd></div>
        <div><dt>{{ B::get('order') }}</dt><dd>{{ B::get('order_'.$order->state->value) }}</dd></div>
        <div><dt>{{ B::get('payment') }}</dt><dd>{{ B::get('payment_'.$order->payment_state->value) }}</dd></div>
    </dl>
    @if($order->payment_state->value === 'partially_refunded')<p class="billing-notice">{{ B::get('partial') }}</p>@endif
    @if($order->payment_state->value === 'refunded')<p class="billing-notice">{{ B::get('full_refund') }}</p>@endif
    @if(in_array($order->state->value, ['pending', 'processing', 'review_required'], true))<p role="status">{{ B::get('payment_pending') }}</p>@endif
    @if($checkout && $checkoutLinkSafe && $order->state->value === 'pending' && $checkoutDeadlineKnown)
    <div data-billing-deadline="{{ $checkoutDeadline }}" data-payment-countdown data-expired-message="{{ B::get('payment_expired') }}">
        <p>{{ B::get('payment_deadline') }} <time datetime="{{ $checkoutDeadline }}">{{ B::date($checkoutDeadline) }}</time> · <span data-countdown></span></p>
        <p role="status" data-deadline-status></p>
        @if($checkoutCanPay)
            <a class="button" href="{{ $checkout['checkout_url'] }}" rel="noreferrer" data-deadline-action>{{ B::get('pay_now') }}</a>
        @else
            <p>{{ B::get('payment_expired') }}</p>
        @endif
    </div>
    @elseif($checkoutCanPay)
        <p>{{ B::get('provider_deadline_unknown') }}</p>
        <a class="button" href="{{ $checkout['checkout_url'] }}" rel="noreferrer">{{ B::get('pay_now') }}</a>
    @elseif($checkout && $order->state->value === 'pending' && ! $checkoutDeadlineKnown && empty($checkout['checkout_url']))
        <p role="status">{{ B::get('provider_deadline_unknown') }}</p>
    @elseif($checkoutLinkSafe && $checkout && $checkout['checkout_url'])
        <p role="status">{{ B::get('payment_link_hidden') }}</p>
    @endif
    @if($canRecheckCheckout && in_array($order->state->value, ['pending', 'processing', 'review_required'], true))
    <form method="post" action="/billing/return/recheck" data-billing-form>
        @csrf<input type="hidden" name="order_id" value="{{ $order->order_id }}">
        <button type="submit">{{ B::get('recheck_order') }}</button>
    </form>
    @endif
    <p><a class="button secondary" href="/billing/return?order_id={{ rawurlencode($order->order_id) }}">{{ B::get('refresh') }}</a></p>
</section>
<section class="card billing-panel">
    @include('audience.billing.membership', ['membership' => $membership])
    <div class="billing-actions"><a class="button" href="/membership">{{ B::get('manage') }}</a>@if($returnTo)<a href="{{ $returnTo }}">{{ B::get('continue') }} →</a>@endif</div>
</section>
</x-layouts.site>
