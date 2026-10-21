@props(['id', 'title'])
<dialog {{ $attributes->class(['h5-admin-dialog']) }} id="{{ $id }}" aria-labelledby="{{ $id }}-title" data-admin-dialog>
    <div class="h5-dialog-header"><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="h5-button" data-admin-close aria-label="关闭弹窗">关闭</button></div>
    <div class="h5-dialog-body">{{ $slot }}</div>
    @isset($actions)<div class="h5-dialog-actions">{{ $actions }}</div>@endisset
</dialog>
