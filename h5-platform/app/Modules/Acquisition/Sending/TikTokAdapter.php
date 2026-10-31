<?php

namespace App\Modules\Acquisition\Sending;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class TikTokAdapter implements PlatformAdapter
{
    public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array
    {
        $user = array_filter(['ip' => $context['ip'] ?? null, 'user_agent' => $context['user_agent'] ?? null,
            'ttclid' => $context['ttclid'] ?? null, 'ttp' => $context['ttp'] ?? null], fn ($v) => is_string($v) && $v !== '');
        $body = ['event_source' => 'web', 'event_source_id' => $delivery->external_id, 'data' => [[
            'event' => 'Purchase', 'event_time' => CarbonImmutable::parse($receipt->occurred_at)->timestamp,
            'event_id' => $delivery->event_key, 'user' => (object) $user, 'page' => ['url' => $context['page_url']],
            'properties' => ['currency' => 'USD', 'value' => $receipt->amount_minor / 100],
        ]]];
        if ($testCode !== null) {
            $body['test_event_code'] = $testCode;
        }

        return $body;
    }

    public function send(object $delivery, array $payload, string $token): DeliveryResult
    {
        if ($reason = DeliveryGate::runtimeReason()) {
            return new DeliveryResult('blocked', $reason);
        }
        if (config('acquisition.transport') !== 'http' || config('acquisition.external_enabled') !== true || config('acquisition.platform_reviewed') !== true) {
            return new DeliveryResult('blocked', 'EXTERNAL_REVIEW_REQUIRED');
        }
        if (! preg_match('/^[A-Za-z0-9_-]{5,64}$/', $delivery->external_id)) {
            return new DeliveryResult('blocked', 'TIKTOK_ASSET_INVALID');
        }
        $response = Http::acceptJson()->asJson()->withHeaders(['Access-Token' => $token])->timeout(12)->connectTimeout(4)
            ->withoutRedirecting()->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload);
        $body = $response->json();
        if ($response->successful() && is_array($body) && ($body['code'] ?? null) === 0) {
            return new DeliveryResult('api_accepted');
        }
        $retry = $response->status() === 429 || $response->serverError();

        return new DeliveryResult($retry ? 'retry' : 'failed', $retry ? 'TIKTOK_RETRYABLE' : 'TIKTOK_REJECTED');
    }
}
