@php($audience = app()->getLoadedProviders()[\App\Modules\Audience\AudienceServiceProvider::class] ?? false)
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title ?? 'H5' }} · H5</title><link rel="stylesheet" href="/identity.css"><script src="/identity.js" defer></script>
@if($audience)<link rel="stylesheet" href="/audience/site.css"><script src="/audience/site.js" defer></script>@endif
@stack('head')</head>
<body class="{{ $audience ? 'audience-site' : '' }}">
@if($audience)
<a class="skip-link" href="#main">{{ __('audience::messages.browse') }}</a>
<header class="site-header"><a class="brand" href="/"><span class="brand-mark" aria-hidden="true">▶</span> {{ __('audience::messages.brand') }}</a>
<nav aria-label="{{ __('audience::messages.home') }}"><a href="/" @if(request()->is('/')) aria-current="page" @endif>{{ __('audience::messages.home') }}</a><a href="/catalog" @if(request()->is('catalog')) aria-current="page" @endif>{{ __('audience::messages.catalog') }}</a><a data-audience-membership href="/membership">{{ __('audience::messages.membership') }}</a></nav>
<div class="header-tools"><a data-audience-login href="{{ auth('web')->check() ? '/identity/account' : '/identity/login?'.http_build_query(['return_to'=>request()->getRequestUri()]) }}">{{ auth('web')->check() ? __('audience::messages.account') : __('audience::messages.login') }}</a>
<details class="language"><summary>{{ app()->getLocale() === 'zh_CN' ? '简体中文' : 'English' }} ▾</summary><div><a href="{{ request()->url().'?'.http_build_query(array_merge(request()->except(['lang', 'cursor']), ['lang'=>'zh_CN'])) }}" lang="zh-Hans">简体中文</a><a href="{{ request()->url().'?'.http_build_query(array_merge(request()->except(['lang', 'cursor']), ['lang'=>'en'])) }}" lang="en">English</a></div></details></div></header>
@if(app(\App\Contracts\RegionResolver::class)->resolve()->source === 'local_us_test')<div class="environment-note">{{ __('audience::messages.local') }}</div>@endif
@else
<header><a class="brand" href="/">H5 <span>{{ __('identity.foundation') }}</span></a><nav><a href="?lang=zh_CN">简体中文</a><a href="?lang=en">English</a><a href="/identity/account">{{ __('identity.account') }}</a><a href="/admin">{{ __('identity.admin') }}</a></nav></header>
@endif
@if($audience && config('h5.modules.acquisition.ready') && config('h5.measurement_c_experience'))
    @includeIf('audience.partials.measurement-c-notice')
@endif
<main id="main">{{ $slot }}
@if($audience && request()->is('identity/account'))<p><a class="button" href="/membership">{{ __('audience::messages.membership') }}</a></p>@endif
</main><footer>@if($audience)<strong>{{ __('audience::messages.brand') }}</strong><p>{{ __('audience::messages.footer') }}</p>@if(app()->environment('local'))<small>{{ __('audience::messages.local_detail') }}</small>@endif @else{{ __('identity.local_boundary') }}@endif</footer>
@if($audience && config('h5.modules.acquisition.ready'))
    @if(config('h5.measurement_c_experience'))
        @includeIf('audience.partials.measurement-c')
    @else
        @includeIf('audience.partials.tracking')
    @endif
@endif
@stack('scripts')</body></html>
