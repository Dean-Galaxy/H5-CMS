@props(['id', 'title'])
<dialog {{ $attributes->class(['h5-admin-dialog']) }} id="{{ $id }}" aria-labelledby="{{ $id }}-title" data-admin-dialog>
    <div class="h5-dialog-header"><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="h5-button h5-dialog-close" data-admin-close aria-label="关闭弹窗"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div>
    <div class="h5-dialog-body">{{ $slot }}</div>
    @isset($actions)<div class="h5-dialog-actions">{{ $actions }}</div>@endisset
</dialog>
