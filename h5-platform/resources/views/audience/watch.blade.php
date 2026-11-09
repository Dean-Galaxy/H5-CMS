<x-layouts.site :title="$drama->title.' · '.$episode->number">
@push('head')<script src="/vendor/hls.js/1.7.3/hls.min.js" integrity="sha256-oS5+4c1kpp3NsxQVfkXa/LpwW/sLFEC3k1yyZdN0Qj4=" crossorigin="anonymous" defer></script>@endpush
<a class="back-link" href="/dramas/{{ $drama->id }}">← {{ __('audience::messages.back') }}</a>
<section class="watch-layout" data-player data-drama="{{ $drama->id }}" data-episode="{{ $episode->id }}" data-duration="{{ $episode->duration_seconds }}" data-viewer="{{ auth('web')->id() }}">
<div>
<div class="player-frame"><video controls playsinline preload="none" aria-label="{{ $episode->title ?: __('audience::messages.episode', ['number'=>$episode->number]) }}"></video><button class="play-button" type="button" data-play>▶ {{ __('audience::messages.play') }}</button></div>
{{-- The manual quality pilot is limited to the episode with verified renditions. --}}
@if($episode->id === '01a1a1a4-4482-722c-81c4-1c0161b549e0')
<div class="actions">
    <label for="watch-quality">{{ __('audience::messages.quality') }}</label>
    <select id="watch-quality" class="button secondary" data-watch-quality autocomplete="off">
        <option value="auto">{{ __('audience::messages.quality_auto') }}</option>
        <option value="hd">{{ __('audience::messages.quality_hd') }}</option>
    </select>
    <span class="muted" data-watch-resolution role="status">{{ __('audience::messages.quality_waiting') }}</span>
</div>
@endif
</div>
<div class="watch-information"><p class="eyebrow">{{ __('audience::messages.episode', ['number'=>$episode->number]) }}</p><h1>{{ $drama->title }}</h1><p>{{ $episode->title }}</p><p role="status" data-player-status>{{ __('audience::messages.play') }}</p><p class="muted" data-progress-status>{{ __('audience::messages.'.(auth('web')->check() ? 'account_progress' : 'guest_progress')) }}</p><div class="actions"><a class="button secondary" data-watch-membership href="/membership">{{ __('audience::messages.membership') }}</a>@guest('web')<a data-watch-login href="/identity/login?{{ http_build_query(['return_to'=>request()->getPathInfo()]) }}">{{ __('audience::messages.login') }}</a>@endguest</div><p class="fine-print">{{ __('audience::messages.subtitle_note') }}</p></div>
</section>
@include('audience.partials.episodes')
@push('scripts')<script id="audience-messages" type="application/json">{!! json_encode(__('audience::messages'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script><script src="/audience/player.js?v={{ substr(hash_file('sha256', public_path('audience/player.js')), 0, 12) }}" defer></script>@endpush
</x-layouts.site>
