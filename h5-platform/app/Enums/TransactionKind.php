<?php

namespace App\Enums;

enum TransactionKind: string
{
    case FirstSubscription = 'first_subscription';
    case Resubscription = 'resubscription';
    case Renewal = 'renewal';
}
