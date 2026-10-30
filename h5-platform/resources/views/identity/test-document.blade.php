<x-layouts.site>
    <section class="card">
        <p role="note"><strong>{{ __('identity.r1_title') }}</strong></p>
        <h1>{{ __('identity.r1_'.$document.'_title') }}</h1>
        <p>{{ $document === 'terms' ? \App\Modules\Identity\Services\RegistrationTerms::TERMS : \App\Modules\Identity\Services\RegistrationTerms::PRIVACY }}</p>
        <p>{{ __('identity.r1_'.$document.'_body') }}</p>
        <a href="/identity/register">{{ __('identity.r1_back') }}</a>
    </section>
</x-layouts.site>
