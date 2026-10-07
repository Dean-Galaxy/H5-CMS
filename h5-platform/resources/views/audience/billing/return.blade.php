@php
    use App\Modules\Audience\Billing\BillingText as B;
    $payable = $checkout && $order->state->value === 'pending' && $checkout['checkout_url'] && $checkout['expires_at'] && str_starts_with($checkout['checkout_url'], 'https://checkout.stripe.com/');
@endphp
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
    @if($payable)
    <div data-billing-deadline="{{ $checkout['expires_at'] }}" data-payment-countdown data-expired-message="{{ B::get('payment_expired') }}">
        <p>{{ B::get('payment_deadline') }} <time datetime="{{ $checkout['expires_at'] }}">{{ B::date($checkout['expires_at']) }}</time> · <span data-countdown></span></p>
        <p role="status" data-deadline-status></p>
        @if(\Carbon\CarbonImmutable::parse($checkout['expires_at'])->isFuture())
        <a class="button" href="{{ $checkout['checkout_url'] }}" rel="noreferrer" data-deadline-action>{{ B::get('pay_now') }}</a>
        @else<p>{{ B::get('payment_expired') }}</p>@endif
    </div>
    @endif
    <p><a class="button secondary" href="/billing/return?order_id={{ rawurlencode($order->order_id) }}">{{ B::get('refresh') }}</a></p>
</section>
<section class="card billing-panel">
    @include('audience.billing.membership', ['membership' => $membership])
    <div class="billing-actions"><a class="button" href="/membership">{{ B::get('manage') }}</a>@if($returnTo)<a href="{{ $returnTo }}">{{ B::get('continue') }} →</a>@endif</div>
</section>
</x-layouts.site>
