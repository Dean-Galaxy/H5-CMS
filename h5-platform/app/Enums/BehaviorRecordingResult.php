<?php

namespace App\Enums;

enum BehaviorRecordingResult: string
{
    case Accepted = 'accepted';
    case Skipped = 'skipped';
    case Retry = 'retry';
}
