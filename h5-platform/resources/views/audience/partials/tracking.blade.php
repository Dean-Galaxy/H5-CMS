@once
<div data-audience-tracking data-buffer-scope="{{ hash_hmac('sha256', session()->getId().'|'.(auth('web')->id() ?? 'guest'), config('app.key')) }}">
    <button type="button" class="secondary" data-privacy-open>{{ __('audience::messages.privacy_choices') }}</button>
    <dialog data-privacy-dialog aria-labelledby="privacy-heading">
        <form data-privacy-form>
            <h2 id="privacy-heading">{{ __('audience::messages.privacy_choices') }}</h2>
            <p>{{ __('audience::messages.privacy_intro') }}</p>
            <p data-privacy-advertising-test hidden>{{ __('audience::messages.privacy_advertising_test') }}</p>
            <label><input type="checkbox" name="analytics"> {{ __('audience::messages.privacy_analytics') }}</label>
            <label><input type="checkbox" name="advertising"> {{ __('audience::messages.privacy_advertising') }}</label>
            <p class="fine-print">{{ __('audience::messages.privacy_retention') }}</p>
            <div class="actions">
                <button type="submit">{{ __('audience::messages.privacy_save') }}</button>
                <button type="button" class="secondary" data-privacy-reject>{{ __('audience::messages.privacy_reject') }}</button>
                <button type="button" class="secondary" data-privacy-withdraw>{{ __('audience::messages.privacy_withdraw') }}</button>
                <button type="button" class="secondary" data-privacy-close>{{ __('audience::messages.privacy_close') }}</button>
            </div>
            <p role="status" aria-live="polite" data-privacy-status data-unavailable="{{ __('audience::messages.privacy_unavailable') }}" data-saved="{{ __('audience::messages.privacy_saved') }}" data-reading="{{ __('audience::messages.privacy_reading') }}" data-disabled="{{ __('audience::messages.privacy_disabled') }}" data-read-failed="{{ __('audience::messages.privacy_read_failed') }}" data-saving="{{ __('audience::messages.privacy_saving') }}"></p>
        </form>
    </dialog>
</div>
<link rel="stylesheet" href="/audience/analytics.css">
<script src="/audience/analytics.js" defer></script>
@endonce
