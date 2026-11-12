<?php

namespace App\Modules\Acquisition\Probe;

use Carbon\CarbonImmutable;

/** Meta's official CAPI template maps page_view to PageView. No Purchase fields or browser SDK. */
final class MetaPageTest
{
    public function payload(string $id, string $time, array $context, string $testCode): array
    {
        return ['data' => [[
            'event_name' => 'PageView', 'event_time' => CarbonImmutable::parse($time)->timestamp,
            'event_id' => 'h5_probe_'.$id, 'action_source' => 'website', 'event_source_url' => $context['page_url'],
            'user_data' => ['client_ip_address' => $context['ip'], 'client_user_agent' => $context['user_agent']],
        ]], 'test_event_code' => $testCode];
    }
}
