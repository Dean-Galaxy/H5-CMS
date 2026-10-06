<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title ?? 'H5' }} · H5</title><link rel="stylesheet" href="/identity.css"><script src="/identity.js" defer></script>@stack('head')</head>
<body><header><a class="brand" href="/">H5 <span>{{ __('identity.foundation') }}</span></a><nav><a href="?lang=zh_CN">简体中文</a><a href="?lang=en">English</a><a href="/identity/account">{{ __('identity.account') }}</a><a href="/admin">{{ __('identity.admin') }}</a></nav></header>
<main>{{ $slot }}</main><footer>{{ __('identity.local_boundary') }}</footer>@stack('scripts')</body></html>
