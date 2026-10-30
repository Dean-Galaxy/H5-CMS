@once
@php
    $cPage = match (true) {
        request()->is('/') => 'home', request()->is('catalog') => 'catalog',
        request()->is('dramas/*/episodes/*') => 'player', request()->is('dramas/*') => 'drama',
        request()->is('identity/register') => 'registration', request()->is('identity/login') => 'login',
        request()->is('identity/email*') => 'verification', request()->is('identity/*') => 'account',
        request()->is('billing/return*') => 'payment_return', request()->is('billing/*') => 'checkout',
        default => 'membership',
    };
@endphp
<div class="measurement-c-controls" data-measurement-c @if(config('h5.measurement_c_registration')) data-c-registration @endif data-page-type="{{ $cPage }}" data-locale="{{ app()->getLocale() }}">
    <button type="button" class="secondary" data-c-open>{{ __('audience::measurement.privacy') }}</button>
    <button type="button" class="secondary" data-c-details>{{ __('audience::measurement.details') }}</button>
    <dialog class="measurement-c-dialog" data-c-dialog aria-labelledby="measurement-c-title">
        <div class="measurement-c-body">
            <h2 id="measurement-c-title" tabindex="-1" autofocus>{{ __('audience::measurement.privacy') }}</h2>
            <p>{{ __(config('h5.measurement_c_registration') ? 'audience::purposes.intro' : 'audience::measurement.scope') }}</p>
            <p role="status" aria-live="polite" data-c-status>{{ __('audience::measurement.checking') }}</p>
            <p data-c-storage hidden>{{ __('audience::measurement.storage_failed') }}</p>
            <dl>
                <dt>{{ __('audience::measurement.choice') }}</dt><dd data-c-choice>{{ __('audience::measurement.unknown') }}</dd>
                <dt>{{ __('audience::measurement.processing') }}</dt><dd data-c-processing>{{ __('audience::measurement.unknown') }}</dd>
                <dt>{{ __('audience::measurement.local') }}</dt><dd data-c-local>{{ __('audience::measurement.waiting') }}</dd>
            </dl>
            @if(config('h5.measurement_c_registration'))
                @include('audience.partials.purposes-r1')
            @else
                <p>{{ __('audience::measurement.not_open') }}</p>
            @endif
            <p data-c-signal hidden>{{ __('audience::measurement.signal') }}</p>
            <details data-c-use><summary>{{ __('audience::measurement.details') }}</summary>
                <p>{{ __('audience::measurement.purpose') }}</p>
                <p>{{ __('audience::measurement.retention') }}</p>
                <p>{{ __('audience::measurement.exit') }}</p>
            </details>
        </div>
        <div class="measurement-c-actions">
            <button type="button" data-c-off>{{ __('audience::measurement.turn_off') }}</button>
            <button type="button" class="secondary" data-c-retry>{{ __('audience::measurement.retry') }}</button>
            <button type="button" class="secondary" data-c-close>{{ __('audience::measurement.close') }}</button>
        </div>
    </dialog>
</div>
<noscript><style>[data-c-open],[data-c-off],[data-c-details],[data-c-status]{display:none!important}</style><div class="measurement-c-notice">
    <p>{{ __('audience::measurement.no_script') }}</p>
    <details><summary>{{ __('audience::measurement.details') }}</summary>
        <p>{{ __('audience::measurement.purpose') }}</p>
        <p>{{ __('audience::measurement.retention') }}</p>
        <p>{{ __('audience::measurement.exit') }}</p>
    </details>
</div></noscript>
<script type="application/json" id="measurement-c-messages">{!! json_encode(__('audience::measurement'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<link rel="stylesheet" href="/audience/measurement-c.css">
<script src="/audience/measurement-c.js" defer></script>
@if(config('h5.measurement_c_registration'))
<script type="application/json" id="purpose-r1-messages">{!! json_encode(__('audience::purposes'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<link rel="stylesheet" href="/audience/purposes-r1.css">
<script src="/audience/purposes-r1.js" defer></script>
@endif
@endonce
