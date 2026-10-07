@php
    use App\Modules\Audience\Billing\BillingText as B;
    use App\Enums\SubscriptionAction as Action;
@endphp
<x-layouts.site :title="B::get('membership')">
@include('audience.billing.assets')
<section class="card billing-panel">
    <p class="eyebrow">H5 · {{ B::get('weekly') }}</p><h1>{{ B::get('membership') }}</h1>
    <p>{{ B::get('intro') }}</p>
    @if(app()->environment(['local', 'testing']))<p class="billing-notice">{{ B::get('test') }}</p>@endif
    @if(session('audience.billing.notice'))<p role="status" class="billing-notice">{{ B::get(session('audience.billing.notice')) }}</p>@endif
    @if($errors->any())<p role="alert" class="billing-notice">{{ B::get('error') }}</p>@endif
    @if($problem)<p role="alert" class="billing-notice">{{ B::get($problem) }}</p>@endif
    @if($membership)
        @include('audience.billing.membership', ['membership' => $membership])
        <div class="billing-actions"><a class="button secondary" href="/membership">{{ B::get('refresh') }}</a>
        @if(in_array(Action::Checkout, $membership->allowed_actions, true) && !$offer)<a class="button" href="/membership?purchase=1">{{ B::get('purchase') }}</a>@endif
        @if(in_array(Action::Resume, $membership->allowed_actions, true) && !$quote)<a class="button" href="/membership?resume=1">{{ B::get('resume') }}</a>@endif
        </div>
        @if($pendingAttempt)
        <p>{{ B::get('recheck_note') }}</p>
        <form method="post" action="/membership/checkout" data-billing-form>
            @csrf
            <input type="hidden" name="offer_id" value="{{ $pendingAttempt['offer_id'] }}"><input type="hidden" name="price_version" value="{{ $pendingAttempt['price_version'] }}">
            <input type="hidden" name="recheck" value="1">
            <button type="submit">{{ B::get('recheck_payment') }}</button>
        </form>
        @elseif(in_array(Action::ResolvePayment, $membership->allowed_actions, true) && !$knownOrderId)
        <p>{{ B::get('recovery_missing') }}</p>
        @endif
        @if($knownOrderId)<p><a href="/billing/return?order_id={{ rawurlencode($knownOrderId) }}">{{ B::get('recent_order') }}</a></p>@endif
    @endif
    @if($returnTo)<p><a href="{{ $returnTo }}">{{ B::get('continue') }} →</a></p>@endif
</section>
@if($offer)
<section class="card billing-panel" data-billing-deadline="{{ $offer->expires_at }}" data-expired-message="{{ B::get('offer_expired') }}">
    <h2>{{ B::get('weekly') }}</h2>
    <dl class="billing-facts">
        @if($offer->eligible_for_first_offer !== false)<div><dt>{{ B::get('first_week') }}</dt><dd class="billing-price">{{ B::money($offer->first_week_amount_minor, $offer->currency) }}</dd></div>@endif
        <div><dt>{{ B::get('regular') }}</dt><dd>{{ B::money($offer->regular_amount_minor, $offer->currency) }}</dd></div>
    </dl>
    @if($offer->eligible_for_first_offer === false)<p>{{ B::get('ineligible') }}</p>@endif
    <p>{{ B::get('after_first') }} <strong>{{ B::money($offer->next_renewal_amount_minor, $offer->currency) }}</strong></p>
    <p>{{ B::get('offer_deadline') }} <time datetime="{{ $offer->expires_at }}">{{ B::date($offer->expires_at) }}</time></p>
    <p role="status" data-deadline-status></p>
    @if(!$viewer)
        <p>{{ B::get('eligibility') }}</p><div class="billing-actions">
            <a class="button" href="/identity/login?return_to={{ rawurlencode('/membership?purchase=1') }}">{{ B::get('signin') }}</a>
            <a href="/identity/register?return_to={{ rawurlencode('/membership?purchase=1') }}">{{ B::get('register') }}</a>
        </div>
    @elseif(!$viewer->emailVerified)
        <p>{{ B::get('verify') }}</p><a class="button" href="/identity/account">{{ B::get('verify_action') }}</a>
    @else
        <form method="post" action="/membership/checkout" data-billing-form>
            @csrf
            <input type="hidden" name="offer_id" value="{{ $offer->offer_id }}"><input type="hidden" name="price_version" value="{{ $offer->price_version }}">
            <label class="billing-check"><input type="checkbox" name="confirmed" value="1" required> <span>{{ B::get('confirm_buy') }}</span></label>
            <button type="submit" data-deadline-action>{{ B::get('checkout') }}</button>
        </form>
    @endif
    <p><a href="/membership?purchase=1">{{ B::get('reprice') }}</a></p>
</section>
@endif
@if($quote)
<section class="card billing-panel" data-billing-deadline="{{ $quote->expires_at }}" data-expired-message="{{ B::get('resume_expired') }}">
    <h2>{{ B::get('resume_heading') }}</h2>
    <dl class="billing-facts">
        <div><dt>{{ B::get('original_price') }}</dt><dd class="billing-price">{{ B::money($quote->next_renewal_amount_minor, $quote->currency) }}</dd></div>
        <div><dt>{{ B::get('next_at') }}</dt><dd><time datetime="{{ $quote->next_renewal_at }}">{{ B::date($quote->next_renewal_at) }}</time></dd></div>
        <div><dt>{{ B::get('until') }}</dt><dd>{{ B::date($membership->access_until) }}</dd></div>
    </dl>
    <p>{{ B::get('resume_terms') }}</p>
    <p>{{ B::get('quote_valid') }} <time datetime="{{ $quote->expires_at }}">{{ B::date($quote->expires_at) }}</time></p>
    <p role="status" data-deadline-status></p>
    <form method="post" action="/membership/resume" data-billing-form>
        @csrf
        <input type="hidden" name="quote_id" value="{{ $quote->quote_id }}"><input type="hidden" name="confirmation_version" value="{{ $quote->confirmation_version }}">
        <input type="hidden" name="confirmed_currency" value="{{ $quote->currency }}"><input type="hidden" name="confirmed_amount_minor" value="{{ $quote->next_renewal_amount_minor }}">
        <input type="hidden" name="confirmed_next_renewal_at" value="{{ $quote->next_renewal_at }}">
        <label class="billing-check"><input type="checkbox" name="confirmed" value="1" required> <span>{{ B::get('resume_confirm') }}</span></label>
        <button type="submit" data-deadline-action>{{ B::get('resume_submit') }}</button>
    </form>
    <p><a href="/membership?resume=1">{{ B::get('resume') }}</a></p>
</section>
@endif
@if($membership && in_array(Action::Cancel, $membership->allowed_actions, true))
<section class="card billing-panel">
    <h2>{{ B::get('cancel_heading') }}</h2><p>{{ B::get('cancel_terms') }}</p>
    <p>{{ B::get('until') }}: <strong>{{ B::date($membership->access_until) }}</strong></p>
    <form method="post" action="/membership/cancel" data-billing-form>
        @csrf
        <label class="billing-check"><input type="checkbox" name="confirmed" value="1" required> <span>{{ B::get('cancel_confirm') }}</span></label>
        <button class="secondary" type="submit">{{ B::get('cancel') }}</button>
    </form>
</section>
@endif
</x-layouts.site>
