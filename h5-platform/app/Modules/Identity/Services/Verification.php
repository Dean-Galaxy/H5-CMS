<?php

namespace App\Modules\Identity\Services;

use App\Contracts\AuditRecorder;
use App\Modules\Identity\Models\Viewer;
use App\Support\ApiProblem;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class Verification
{
    public function send(Viewer $viewer): void
    {
        if (RegistrationTerms::enabled() && config('mail.default') !== 'preview'
            && ! (app()->environment('testing') && config('mail.default') === 'array')) {
            throw new ApiProblem('DEPENDENCY_NOT_READY', 503, '本机注册测试只提供私密邮件预览');
        }
        try {
            DB::transaction(function () use ($viewer) {
                $user = Viewer::query()->lockForUpdate()->findOrFail($viewer->id);
                if ($user->hasVerifiedEmail()) {
                    return;
                }
                $user->forceFill(['verification_nonce' => (string) Str::uuid7()])->save();
                try {
                    $user->notify(new VerifyEmail);
                } catch (TransportExceptionInterface) {
                    throw new MailDeliveryUnconfirmed;
                }
                app(AuditRecorder::class)->record('verification.sent', 'viewer', $user->id, 'success', ['mail_mode' => config('mail.default')]);
            });
        } catch (MailDeliveryUnconfirmed $exception) {
            if (! RegistrationTerms::enabled() && request()->routeIs('register.store')
                && request()->attributes->get('identity.created_viewer') === $viewer->id) {
                // The required account already committed. Continue Registered listeners and Fortify login.
                request()->attributes->set('identity.registration_mail_unconfirmed', true);
                request()->session()->flash('identity.ordinary_registration_mail_unconfirmed', true);

                return;
            }
            throw $exception;
        }
    }

    public function url(Viewer $viewer): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $viewer->id, 'hash' => sha1($viewer->getEmailForVerification()), 'nonce' => $viewer->verification_nonce,
        ]);
    }

    public function verify(Request $request, string $id, string $hash): void
    {
        if ((int) $request->query('expires') < now()->timestamp) {
            throw new ApiProblem('TOKEN_EXPIRED', 403, '验证链接已过期，请重新发送');
        }
        if (! $request->hasValidSignature()) {
            throw new ApiProblem('TOKEN_INVALID', 403, '验证链接无效');
        }
        if ($request->user('web')->id !== $id) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '请使用收件邮箱对应的账号登录');
        }
        DB::transaction(function () use ($request, $id, $hash) {
            $user = Viewer::query()->lockForUpdate()->findOrFail($id);
            if ($user->hasVerifiedEmail()) {
                throw new ApiProblem('TOKEN_REPLAYED', 409, '此验证链接已使用');
            }
            if (! hash_equals(sha1($user->email), $hash) || ! hash_equals((string) $user->verification_nonce, (string) $request->query('nonce'))) {
                throw new ApiProblem('TOKEN_INVALID', 403, '验证链接已失效，请使用最新邮件');
            }
            $user->forceFill(['email_verified_at' => now(), 'verification_nonce' => null])->save();
            event(new Verified($user));
            app(AuditRecorder::class)->record('email.verified', 'viewer', $id, 'success');
        });
    }
}
