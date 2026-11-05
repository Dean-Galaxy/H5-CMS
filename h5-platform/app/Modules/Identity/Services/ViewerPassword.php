<?php

namespace App\Modules\Identity\Services;

final class ViewerPassword
{
    public const MIN_LENGTH = 8;

    public static function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:128', 'confirmed'];
    }
}
