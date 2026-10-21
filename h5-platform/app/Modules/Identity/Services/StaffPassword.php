<?php

namespace App\Modules\Identity\Services;

use Illuminate\Validation\Rules\Password;

final class StaffPassword
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 128;

    public const HELP = '8–128 位，包含字母和数字';

    public const OWNER_PROMPT = '密码（8–128 位，含字母和数字，输入不显示）';

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->numbers();
    }

    public static function requiredRules(): array
    {
        return ['required', 'string', self::rule(), 'max:'.self::MAX_LENGTH];
    }
}
