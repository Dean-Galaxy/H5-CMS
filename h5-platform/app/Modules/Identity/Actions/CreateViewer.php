<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\RegistrationTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateViewer implements CreatesNewUsers
{
    public function create(array $input): Viewer
    {
        $input['email'] = mb_strtolower(trim($input['email'] ?? ''));
        $terms = RegistrationTerms::enabled();
        if ($terms && ! request()->isJson()) {
            // Native HTML checkbox: only explicit value=1 is normalized; JSON must be true.
            $input['terms_accepted'] = ($input['terms_accepted'] ?? null) === '1';
        }
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:254', 'unique:identity_viewers,email'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128', 'confirmed'],
        ];
        if ($terms) {
            $rules += [
                'terms_accepted' => ['required', static function ($attribute, $value, $fail): void {
                    if ($value !== true) {
                        $fail(__('identity.r1_accept_error'));
                    }
                }],
                'terms_version' => ['required', 'string', Rule::in([RegistrationTerms::TERMS])],
                'privacy_notice_version' => ['required', 'string', Rule::in([RegistrationTerms::PRIVACY])],
            ];
        }
        Validator::make($input, $rules, ['email.unique' => '无法使用此邮箱注册，请登录或尝试找回密码。'])->validate();

        $viewer = DB::transaction(function () use ($input, $terms): Viewer {
            $viewer = Viewer::create(array_intersect_key($input, array_flip(['name', 'email', 'password'])));
            if ($terms) {
                app(RegistrationTerms::class)->receipt($viewer);
            }

            return $viewer;
        });
        // Server-only outcome marker; middleware records the persisted fact after the action.
        request()->attributes->set('identity.created_viewer', $viewer->id);

        return $viewer;
    }
}
