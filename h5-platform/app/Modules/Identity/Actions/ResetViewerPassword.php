<?php

namespace App\Modules\Identity\Actions;

use App\Contracts\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetViewerPassword implements ResetsUserPasswords
{
    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128', 'confirmed']])->validate();
        $user->forceFill(['password' => $input['password'], 'remember_token' => Str::random(60),
            'session_version' => $user->session_version + 1])->save();
        app(AuditRecorder::class)->record('password.reset', 'viewer', $user->id, 'success');
    }
}
