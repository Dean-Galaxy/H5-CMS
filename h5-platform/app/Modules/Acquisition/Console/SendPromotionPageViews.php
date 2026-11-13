<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Promotion\PageViewSender;
use Illuminate\Console\Command;

final class SendPromotionPageViews extends Command
{
    protected $signature = 'acquisition:promotion-pageviews {--event= : Exact event UUID} {--limit=10 : At most 10 nonfinancial events}';

    protected $description = '受控测试真实推广PageView；无付款扫描、无自动重试';

    public function handle(PageViewSender $sender): int
    {
        try {
            $result = $this->option('event') !== null ? $sender->send((string) $this->option('event')) : $sender->run((int) $this->option('limit'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return isset($result['state'])
                ? ($result['state'] === 'api_accepted' ? self::SUCCESS : self::FAILURE)
                : (array_diff(array_keys($result), ['api_accepted']) ? self::FAILURE : self::SUCCESS);
        } catch (\Throwable) {
            $this->error('PROMOTION_SEND_NOT_CONFIRMED');

            return self::FAILURE;
        }
    }
}
