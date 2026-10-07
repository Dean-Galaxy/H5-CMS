@php
    use App\Modules\Audience\Billing\BillingText as B;
@endphp
<x-layouts.site :title="B::get('membership')">
@include('audience.billing.assets')
<section class="card billing-panel"><h1>{{ B::get('membership') }}</h1><p role="alert">{{ B::get($problem) }}</p><a class="button" href="/membership">{{ B::get('back') }}</a>@if($returnTo)<p><a href="{{ $returnTo }}">{{ B::get('continue') }}</a></p>@endif</section>
</x-layouts.site>
