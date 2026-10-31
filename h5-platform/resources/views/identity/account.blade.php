<x-layouts.site><section class="card"><p class="eyebrow">{{ __('identity.account') }}</p><h1>{{ auth('web')->user()->name }}</h1><p>{{ auth('web')->user()->email }}</p><p class="badge">{{ auth('web')->user()->hasVerifiedEmail() ? __('identity.verified') : __('identity.unverified') }}</p>
@if(session('identity.ordinary_registration_mail_unconfirmed'))<p role="alert">{{ __('identity.registration_mail_unconfirmed') }}</p>@endif
@if(\App\Modules\Identity\Services\RegistrationTerms::enabled())
<p role="note"><strong>{{ __('identity.r1_title') }}</strong></p>
@if(session('identity.registration_created'))<p role="status">{{ __('identity.r1_created') }}</p>@endif
@if(session('identity.registration_mail_unconfirmed'))<p role="alert">{{ __('identity.r1_mail_unconfirmed') }}</p>@endif
<p>{{ __('identity.r1_purpose_summary') }}</p>
<p><a href="{{ app(\App\Modules\Identity\Services\SafeReturn::class)->current() }}">{{ __('identity.r1_continue') }}</a></p>
@endif
@if(!auth('web')->user()->hasVerifiedEmail())<p>{{ __('identity.verify_before_pay') }}</p>@if(config('mail.default') === 'preview')<p>{{ __('identity.mail_preview') }}</p>@endif<form method="post" action="/api/v1/auth/email/verification-notification" data-identity-form>@csrf<button data-cooldown="60">{{ __('identity.resend') }}</button><p role="status" data-result></p></form><details><summary>{{ __('identity.correct_email') }}</summary><form method="post" action="/api/v1/auth/email" data-identity-form>@csrf @method('PATCH')<label>{{ __('identity.new_email') }}<input type="email" name="email" required></label><label>{{ __('identity.password') }}<input type="password" name="password" autocomplete="current-password" required></label><button>{{ __('identity.save_resend') }}</button><p role="status" data-result></p></form></details>@endif
<p>{{ __('identity.no_membership_claim') }}</p><form method="post" action="/api/v1/auth/logout">@csrf<button class="secondary">{{ __('identity.logout') }}</button></form></section></x-layouts.site>
