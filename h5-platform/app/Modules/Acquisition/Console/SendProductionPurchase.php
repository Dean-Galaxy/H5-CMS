<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Production\SinglePurchaseSender;
use Illuminate\Console\Command;
use Throwable;

final class SendProductionPurchase extends Command
{
    protected $signature = 'acquisition:purchase-production {payment : Approved Live payment UUID} {order : Approved initial order UUID}';

    protected $description = '回传获授权的一笔正式 Meta Purchase，单次名额先落库，结果未知不重发';

    public function handle(SinglePurchaseSender $sender): int
    {
        try {
            $result = $sender->send((string) $this->argument('payment'), (string) $this->argument('order'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['state'] === 'api_accepted' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('PRODUCTION_PURCHASE_NOT_CONFIRMED_DO_NOT_RESEND');

            return self::FAILURE;
        }
    }
}
