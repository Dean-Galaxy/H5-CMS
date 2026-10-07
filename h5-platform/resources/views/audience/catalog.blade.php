<x-layouts.site :title="__('audience::messages.catalog')">
<section><p class="eyebrow">{{ __('audience::messages.eyebrow') }}</p><h1>{{ __('audience::messages.catalog') }}</h1><div class="category-pills"><a href="/catalog" @if(!$categoryId) aria-current="page" @endif>{{ __('audience::messages.all') }}</a>@foreach($categories->items as $category)<a href="/catalog?{{ http_build_query(['category_id'=>$category->id]) }}" @if($categoryId === $category->id) aria-current="page" @endif>{{ $category->name }}</a>@endforeach</div></section>
@if($reset)<p role="status" class="notice">{{ __('audience::messages.reset') }}</p>@endif
@include('audience.partials.cards', ['items'=>$page->items])
@if($page->next_cursor)<p class="more"><a class="button secondary" href="/catalog?{{ http_build_query(['category_id'=>$categoryId, 'cursor'=>$page->next_cursor]) }}">{{ __('audience::messages.next') }}</a></p>@endif
</x-layouts.site>
