<?php

namespace App\Modules\Identity\Http;

use App\Contracts\AuditRecorder;
use App\Contracts\IdentityContext;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\MailDeliveryUnconfirmed;
use App\Modules\Identity\Services\Verification;
use App\Modules\Identity\Services\ViewerBehavior;
use App\Support\ApiProblem;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class IdentityController
{
    public function me(IdentityContext $identity): array
    {
        return ['viewer' => $identity->viewer()?->toArray(), 'staff' => null];
    }

    public function csrf(Request $request): array
    {
        return ['csrf_token' => $request->session()->token(), 'request_id' => $request->attributes->get('request_id')];
    }

    private function accepted(Request $request, string $message)
    {
        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return response()->json(['status' => 'accepted', 'message' => $message, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function logout(Request $request)
    {
        app(AuditRecorder::class)->record('logout', 'viewer', Auth::guard('web')->id(), 'success');
        app(ViewerBehavior::class)->logout($request->user('web'));
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? $this->accepted($request, '已退出') : redirect('/identity/login');
    }

    public function forgot(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:254']]);
        // Identical public response for missing, throttled and existing accounts.
        try {
            Password::broker('viewers')->sendResetLink(['email' => mb_strtolower(trim($input['email']))]);
        } catch (MailDeliveryUnconfirmed) {
            // Same public response for an unknown address, throttling and uncertain transport delivery.
        }

        return $this->accepted($request, __('identity.reset_mail_neutral'));
    }

    public function reset(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed'], 'password_confirmation' => ['required', 'string']]);
        $input['email'] = mb_strtolower(trim($input['email']));
        // Serialize reset-token check, password update and token deletion against the same viewer.
        $result = DB::transaction(function () use ($input) {
            Viewer::where('email', $input['email'])->lockForUpdate()->first();

            return Password::broker('viewers')->reset($input, function ($user, $password) use ($input) {
                app(ResetsUserPasswords::class)->reset($user, $input);
                event(new PasswordReset($user));
            });
        });
        if ($result !== Password::PASSWORD_RESET) {
            throw new ApiProblem('TOKEN_INVALID', 422, '重设链接无效、过期或已使用，请重新申请');
        }

        return $this->accepted($request, '密码已重设，旧会话已撤销，请重新登录。');
    }

    public function resend(Request $request)
    {
        $request->user('web')->sendEmailVerificationNotification();

        return $this->accepted($request, '验证邮件已准备；60 秒后可重发。请检查垃圾邮件或本地预览。');
    }

    public function verify(Request $request, string $id, string $hash)
    {
        app(Verification::class)->verify($request, $id, $hash);
        Auth::guard('web')->setUser(Viewer::findOrFail($id));

        return app(AuthResponse::class)->toResponse($request);
    }

    public function correctEmail(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string']]);
        DB::transaction(function () use ($request, $input) {
            $user = Viewer::query()->lockForUpdate()->findOrFail($request->user('web')->id);
            if ($user->hasVerifiedEmail()) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '仅支持首次验证前更正邮箱');
            }
            if (! Hash::check($input['password'], $user->password)) {
                throw new ApiProblem('INVALID_CREDENTIALS', 422, '凭据不正确');
            }
            $email = mb_strtolower(trim($input['email']));
            if (Viewer::where('email', $email)->whereKeyNot($user->id)->exists()) {
                throw new ApiProblem('VALIDATION_FAILED', 422, '无法使用此邮箱');
            }
            DB::table('identity_password_reset_tokens')->where('email', $user->email)->delete();
            $user->forceFill(['email' => $email, 'verification_nonce' => null])->save();
            $user->sendEmailVerificationNotification();
            app(AuditRecorder::class)->record('email.corrected', 'viewer', $user->id, 'success');
        });

        return $this->accepted($request, '邮箱已更正，旧验证链接已失效，请查看新邮件。');
    }
}
