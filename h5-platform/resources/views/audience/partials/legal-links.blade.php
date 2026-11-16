<nav class="legal-links" aria-label="{{ __('audience::legal.links_label') }}">
    <a href="{{ route('audience.terms', ['lang' => app()->getLocale()], false) }}">{{ __('audience::legal.terms.title') }}</a>
    <a href="{{ route('audience.privacy', ['lang' => app()->getLocale()], false) }}">{{ __('audience::legal.privacy.title') }}</a>
</nav>
