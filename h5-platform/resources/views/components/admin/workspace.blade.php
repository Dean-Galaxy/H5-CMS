@props(['title' => null, 'description' => null])
<div {{ $attributes->class(['h5-admin-workspace']) }} data-admin-ui="1">
    @if($title || $description || isset($actions))
        <div class="h5-heading">
            <div>@if($title)<h2>{{ $title }}</h2>@endif @if($description)<p class="h5-muted">{{ $description }}</p>@endif</div>
            @isset($actions)<div class="h5-toolbar">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</div>
