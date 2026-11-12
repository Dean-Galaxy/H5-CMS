<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Probe\PageTestProbe;
use Illuminate\Console\Command;

final class SendPageTest extends Command
{
    protected $signature = 'acquisition:page-test {intent : Exact single intent UUID}';

    protected $description = '受控staging单次Meta页面测试；无付款、无扫描、无自动重试';

    public function handle(PageTestProbe $probe): int
    {
        try {
            $result = $probe->send((string) $this->argument('intent'));
            $this->line('Meta PageView: '.$result['state'].'; '.($result['reason'] ?? 'PLATFORM_CONFIRMATION_PENDING'));

            return $result['state'] === 'api_accepted' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('PAGE_TEST_NOT_CONFIRMED; no automatic retry.');

            return self::FAILURE;
        }
    }
}
