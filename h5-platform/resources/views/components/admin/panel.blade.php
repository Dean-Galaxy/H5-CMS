@props(['group', 'name', 'active'])
<section {{ $attributes->class(['h5-tab-panel']) }} id="{{ $group }}-panel-{{ $name }}" role="tabpanel"
    aria-labelledby="{{ $group }}-tab-{{ $name }}" tabindex="0" data-admin-panel="{{ $name }}" @if($active !== $name) hidden @endif>
    {{ $slot }}
</section>
