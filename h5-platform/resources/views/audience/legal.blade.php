@php
    $text = trans('audience::legal.'.$document);
    $date = config('legal.effective_date');
    $contact = config('legal.contact');
@endphp

<x-layouts.site :title="$text['title']">
    <article class="card legal-page">
        <h1>{{ $text['title'] }}</h1>
        <p>{{ __('audience::legal.operated_by', ['operator' => config('legal.operator')]) }}</p>
        <p>{{ __('audience::legal.effective_date') }} <time datetime="{{ $date }}">{{ $date }}</time></p>

        @foreach($text['sections'] as $section)
            <section>
                <h2>{{ $section['heading'] }}</h2>
                <p>{{ $section['body'] }}</p>
            </section>
        @endforeach

        <section>
            <h2>{{ __('audience::legal.contact') }}</h2>
            <p>{{ $text['contact'] }} <a href="mailto:{{ $contact }}">{{ $contact }}</a></p>
        </section>

        @if($document === 'terms')
            <a href="/membership?lang={{ app()->getLocale() }}">{{ __('audience::legal.membership_link') }}</a>
        @endif
    </article>
</x-layouts.site>
