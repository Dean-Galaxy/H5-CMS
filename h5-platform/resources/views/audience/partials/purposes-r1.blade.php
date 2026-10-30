<section class="purpose-manager" data-c-purpose-manager data-signed-in="{{ auth('web')->check() ? '1' : '0' }}">
    <p role="status" aria-live="polite" data-purpose-reading>{{ __('audience::purposes.reading') }}</p>
    <button type="button" class="secondary" data-purpose-refresh>{{ __('audience::purposes.refresh') }}</button>
    @foreach(['account_link' => 'account_info', 'advertising' => 'advertising_info'] as $purpose => $info)
        <fieldset data-purpose="{{ $purpose }}">
            <legend>{{ __('audience::purposes.'.$purpose) }}</legend>
            <p>{{ __('audience::purposes.'.$info) }}</p>
            <dl>
                <dt>{{ __('audience::purposes.choice') }}</dt><dd data-purpose-choice>{{ __('audience::purposes.unconfirmed') }}</dd>
                <dt>{{ __('audience::purposes.capability') }}</dt><dd data-purpose-capability>{{ __('audience::purposes.unconfirmed') }}</dd>
                <dt>{{ __('audience::purposes.effective') }}</dt><dd data-purpose-effective>{{ __('audience::purposes.unconfirmed') }}</dd>
            </dl>
            <label><input type="radio" name="purpose-{{ $purpose }}" value="allow"> {{ __('audience::purposes.allow') }}</label>
            <label><input type="radio" name="purpose-{{ $purpose }}" value="deny"> {{ __('audience::purposes.deny') }}</label>
            <div class="purpose-buttons"><button type="button" data-purpose-save disabled>{{ __('audience::purposes.save') }}</button>
                <button type="button" class="secondary" data-purpose-deny>{{ __('audience::purposes.deny') }}</button></div>
            <p role="status" aria-live="polite" data-purpose-result></p>
            <a data-purpose-login hidden href="/identity/login?{{ http_build_query(['return_to'=>request()->getPathInfo()]) }}">{{ __('audience::purposes.login') }}</a>
            <details><summary>{{ __('audience::purposes.technical') }}</summary><p data-purpose-technical></p></details>
        </fieldset>
    @endforeach
    <p><strong>{{ __('audience::purposes.legacy') }}</strong>: <span data-purpose-legacy>{{ __('audience::purposes.unconfirmed') }}</span></p>
    <p>{{ __('audience::purposes.legacy_info') }}</p>
    <details><summary>{{ __('audience::measurement.details') }}</summary><p>{{ __('audience::purposes.scope') }}</p><p>{{ __('audience::purposes.retention') }}</p></details>
</section>
