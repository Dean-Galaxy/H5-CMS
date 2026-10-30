<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Services\RegistrationObservation;
use App\Modules\Identity\Services\RegistrationTerms;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Throwable;

final class ViewerRegistrationController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        if (! RegistrationTerms::enabled()) {
            return app(RegisteredUserController::class)->store($request, $creator);
        }

        $observation = new RegistrationObservation;
        $observation->begin($request);
        try {
            $viewer = $creator->create($request->all()); // Account and required receipt commit together.
        } catch (Throwable $exception) {
            $observation->failed($exception);
            throw $exception;
        }
        $observation->succeeded(); // Before Registered/mail/automatic Login can clear the old C context.
        try {
            event(new Registered($viewer));
        } catch (Throwable $exception) {
            // Notification failure cannot turn a committed account into a failed registration.
            $request->session()->flash('identity.registration_mail_unconfirmed', true);
            Log::warning('identity.test_registration_notification_unconfirmed', [
                'exception_type' => $exception::class,
                'request_id' => $request->attributes->get('request_id'),
            ]);
        }
        Auth::guard('web')->login($viewer, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->flash('identity.registration_created', true);

        return app(RegisterResponse::class);
    }
}
