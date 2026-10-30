<?php

namespace App\Enums;

enum RegistrationFailure: string
{
    case Validation = 'validation';
    case Conflict = 'conflict';
    case Temporary = 'temporary';
    case Unknown = 'unknown';
}
