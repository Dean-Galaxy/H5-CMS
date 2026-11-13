<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Services\SinglePurchaseTestSender;
use Illuminate\Console\Command;
use Throwable;

final class SendPurchaseTest extends Command
{
    protected $signature = 'acquisition:purchase-test {delivery : Exact fresh delivery UUID verified by 00/03}';

    protected $description = '受控staging单条测试Purchase；名额先消耗，结果未知不重发，无扫描/重置/重试';

    public function handle(SinglePurchaseTestSender $sender): int
    {
        try {
            $result = $sender->send((string) $this->argument('delivery'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['state'] === 'api_accepted' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('PURCHASE_TEST_NOT_CONFIRMED_DO_NOT_RESEND');

            return self::FAILURE;
        }
    }
}
