<?php

namespace App\Enums;

enum RegistrationResultKind: string
{
    case Succeeded = 'registration_succeeded';
    case Failed = 'registration_failed';
}
