@props(['id', 'items' => [], 'active' => null, 'method' => null, 'label' => '功能分栏'])
@php
    if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', $id) || ($method !== null && ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $method))) {
        throw new \InvalidArgumentException('后台标签组和方法必须使用代码定义的标识。');
    }
    $tabIds = [];
    foreach ($items as $item) {
        if (! is_string($item['id'] ?? null) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', $item['id']) || in_array($item['id'], $tabIds, true)) {
            throw new \InvalidArgumentException('后台标签必须使用唯一的代码标识。');
        }
        $tabIds[] = $item['id'];
    }
    $active = in_array($active, $tabIds, true) ? $active : ($tabIds[0] ?? null);
@endphp
<div {{ $attributes->class(['h5-tabs']) }} id="{{ $id }}" role="tablist" aria-label="{{ $label }}" data-admin-tabs data-admin-mode="{{ $method ? 'livewire' : 'local' }}">
    @foreach($items as $item)
        <button type="button" class="h5-tab" id="{{ $id }}-tab-{{ $item['id'] }}"
            data-admin-tab="{{ $item['id'] }}" role="tab" aria-controls="{{ $id }}-panel-{{ $item['id'] }}"
            aria-selected="{{ $active === $item['id'] ? 'true' : 'false' }}" tabindex="{{ $active === $item['id'] ? '0' : '-1' }}"
            @if($method) wire:key="{{ $id }}-tab-{{ $item['id'] }}" wire:click="{{ $method }}('{{ $item['id'] }}')" wire:loading.attr="aria-disabled" @endif>
            {{ $item['label'] }}@isset($item['count'])<span class="h5-count">{{ $item['count'] }}</span>@endisset
        </button>
    @endforeach
</div>
