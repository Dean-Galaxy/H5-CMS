<?php

namespace App\Enums;

enum OrderState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case ReviewRequired = 'review_required';
}
