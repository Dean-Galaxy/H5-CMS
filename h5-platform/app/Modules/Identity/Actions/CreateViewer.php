<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateViewer implements CreatesNewUsers
{
    public function create(array $input): Viewer
    {
        $input['email'] = mb_strtolower(trim($input['email'] ?? ''));
        Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:254', 'unique:identity_viewers,email'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128', 'confirmed'],
        ], ['email.unique' => '无法使用此邮箱注册，请登录或尝试找回密码。'])->validate();

        $viewer = Viewer::create(array_intersect_key($input, array_flip(['name', 'email', 'password'])));
        // Server-only outcome marker; middleware records the persisted fact after the action.
        request()->attributes->set('identity.created_viewer', $viewer->id);

        return $viewer;
    }
}
