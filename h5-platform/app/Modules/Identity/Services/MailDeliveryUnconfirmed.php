<?php

namespace App\Modules\Identity\Services;

use App\Support\ApiProblem;
use Illuminate\Support\Facades\Log;

/** Only constructed at the notification transport boundary; never carries SMTP diagnostics. */
final class MailDeliveryUnconfirmed extends ApiProblem
{
    public function __construct()
    {
        Log::warning('identity.mail_delivery_unconfirmed', [
            'failure_kind' => 'transport',
            'request_id' => request()->attributes->get('request_id'),
        ]);
        parent::__construct('MAIL_DELIVERY_UNCONFIRMED', 503, __('identity.mail_delivery_unconfirmed'), true);
    }
}
