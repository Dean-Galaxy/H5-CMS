<div class="story-grid">
@forelse($items as $story)
<article class="story-card"><a href="/dramas/{{ $story->id }}">
<div class="poster">@if($story->cover_url)<img src="{{ $story->cover_url }}" alt="" loading="lazy">@else<span class="poster-placeholder" aria-hidden="true">▶</span>@endif<span class="poster-badge">{{ __('audience::messages.published', ['count'=>$story->published_episode_count]) }}</span></div>
<h3>{{ $story->title }}</h3><p>{{ __('audience::messages.free_count', ['count'=>$story->free_episode_count]) }}</p>
</a></article>
@empty
<div class="empty-state"><span aria-hidden="true">✦</span><h2>{{ __('audience::messages.empty') }}</h2><p>{{ __('audience::messages.empty_detail') }}</p></div>
@endforelse
</div>
