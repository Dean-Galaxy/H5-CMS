<?php

namespace App\Enums;

enum SubscriptionAction: string
{
    case Checkout = 'checkout';
    case Cancel = 'cancel';
    case Resume = 'resume';
    case ResolvePayment = 'resolve_payment';
}
