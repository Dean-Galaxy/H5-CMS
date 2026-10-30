<x-layouts.site>
    @php($r1 = \App\Modules\Identity\Services\RegistrationTerms::enabled())
    <section class="card {{ $r1 ? 'identity-registration-r1' : '' }}">
        <h1>{{ __('identity.register') }}</h1>
        @if($r1)<p role="note"><strong>{{ __('identity.r1_title') }}</strong></p>
        <p data-registration-notice>{{ __('identity.r1_result_notice') }}</p>@endif
        <p>{{ __('identity.verify_before_pay') }}</p>
        <form method="post" action="/api/v1/auth/register" data-identity-form
            @if($r1) data-registration-r1 data-unknown="{{ __('identity.r1_unknown') }}" data-confirmed="{{ __('identity.r1_confirmed') }}" @endif>
            @csrf
            <label>{{ __('identity.name') }}<input name="name" maxlength="80" autocomplete="nickname" required></label>
            <label>{{ __('identity.email') }}<input type="email" name="email" autocomplete="username" required></label>
            <label>{{ __('identity.password_hint') }}<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
            <label>{{ __('identity.confirm_password') }}<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
            @if($r1)
                <input type="hidden" name="terms_version" value="{{ \App\Modules\Identity\Services\RegistrationTerms::TERMS }}">
                <input type="hidden" name="privacy_notice_version" value="{{ \App\Modules\Identity\Services\RegistrationTerms::PRIVACY }}">
                <label class="identity-check"><input type="checkbox" name="terms_accepted" value="1" required> <span>{{ __('identity.r1_terms_label') }}</span></label>
                <p><a href="/identity/test-terms" target="_blank" rel="noopener">{{ __('identity.r1_terms_title') }}</a> · <a href="/identity/test-privacy" target="_blank" rel="noopener">{{ __('identity.r1_privacy_title') }}</a></p>
                <p>{{ __('identity.r1_required_note') }}</p>
                <p>{{ __('identity.r1_site_note') }}</p>
                <p data-purpose-site data-c-status>{{ __('identity.r1_purpose_text.checking') }}</p>
                @if(config('h5.modules.acquisition.ready') && config('h5.modules.audience.ready'))
                <button type="button" class="secondary" data-c-open>{{ __('identity.r1_privacy_settings') }}</button>
                @endif
                <fieldset data-registration-purposes aria-describedby="identity-purposes-unavailable">
                    <legend>{{ __('identity.r1_optional_title') }}</legend>
                    <label class="identity-check"><input type="checkbox" disabled data-registration-purpose="account_link"> <span>{{ __('identity.r1_account_choice') }}</span></label>
                    <label class="identity-check"><input type="checkbox" disabled data-registration-purpose="advertising"> <span>{{ __('identity.r1_advertising_choice') }}</span></label>
                    @foreach(['account_link', 'advertising'] as $purpose)
                    <p role="status" data-purpose-state="{{ $purpose }}">{{ __('identity.r1_purpose_text.checking') }}</p>
                    <button type="button" data-purpose-retry="{{ $purpose }}" hidden>{{ __('identity.r1_purpose_text.retry') }}</button>
                    <button type="button" class="secondary" data-purpose-latest="{{ $purpose }}" hidden>{{ __('identity.r1_purpose_text.latest') }}</button>
                    <details><summary>{{ __('identity.r1_technical') }}</summary><p data-purpose-detail="{{ $purpose }}"></p></details>
                    @endforeach
                    <p data-purpose-legacy></p>
                </fieldset>
                <script type="application/json" data-purpose-messages>{!! json_encode(__('identity.r1_purpose_text'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
                <p id="identity-purposes-unavailable">{{ __('identity.r1_unavailable') }}</p>
                <p>{{ __('identity.r1_optional_note') }}</p>
                <p>{{ __('identity.r1_scope') }}</p>
                <details><summary>{{ __('identity.r1_technical') }}</summary><p>{{ \App\Modules\Identity\Services\RegistrationObservation::NOTICE }}</p><p data-registration-error-detail></p></details>
            @endif
            <button type="submit">{{ __($r1 ? 'identity.r1_create' : 'identity.register') }}</button>
            <p role="status" data-result></p>
            @if($r1)<button type="button" data-registration-check hidden>{{ __('identity.r1_check') }}</button>
            <a href="/identity/account" class="button" data-registration-continue hidden>{{ __('identity.r1_keep_using') }}</a>
            <noscript><p>{{ __('identity.r1_no_js') }}</p></noscript>@endif
        </form>
        <a href="/identity/login">{{ __('identity.have_account') }}</a>
        @if($r1)<p>{{ __('identity.r1_recovery_help') }}</p><p><a href="/identity/forgot-password">{{ __('identity.forgot') }}</a></p>@endif
    </section>
</x-layouts.site>
