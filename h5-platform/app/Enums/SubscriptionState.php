<?php

namespace App\Enums;

enum SubscriptionState: string
{
    case None = 'none';
    case Pending = 'pending';
    case Active = 'active';
    case Canceling = 'canceling';
    case PastDue = 'past_due';
    case Ended = 'ended';
    case Unknown = 'unknown';
}
