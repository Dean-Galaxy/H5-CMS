<?php

namespace App\Modules\Acquisition\Sending;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class MetaAdapter implements PlatformAdapter
{
    public function payload(object $delivery, object $receipt, array $context, ?string $testCode): array
    {
        $user = array_filter([
            'client_ip_address' => $context['ip'] ?? null, 'client_user_agent' => $context['user_agent'] ?? null,
            'fbc' => $context['fbc'] ?? null, 'fbp' => $context['fbp'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
        $body = ['data' => [[
            'event_name' => 'Purchase', 'event_time' => CarbonImmutable::parse($receipt->occurred_at)->timestamp,
            'event_id' => $delivery->event_key, 'action_source' => $receipt->transaction_kind === 'renewal' ? 'system_generated' : 'website',
            'event_source_url' => $context['page_url'], 'user_data' => $user,
            'custom_data' => ['currency' => 'USD', 'value' => $receipt->amount_minor / 100,
                'transaction_kind' => $receipt->transaction_kind, 'discount_applied' => (bool) $receipt->discount_applied],
        ]]];
        if ($testCode !== null) {
            $body['test_event_code'] = $testCode;
        }

        return $body;
    }

    public function send(object $delivery, array $payload, string $token): DeliveryResult
    {
        $version = config('acquisition.meta_api_version');
        if (! is_string($version) || ! preg_match('/^v\d{2}\.0$/', $version) || ! preg_match('/^[0-9]{5,30}$/', $delivery->external_id)) {
            return new DeliveryResult('blocked', 'META_VERSION_OR_ASSET_MISSING');
        }
        $response = Http::acceptJson()->asJson()->withToken($token)->timeout(12)->connectTimeout(4)
            ->withoutRedirecting()->post('https://graph.facebook.com/'.$version.'/'.$delivery->external_id.'/events', $payload);
        $body = $response->json();
        if ($response->successful() && is_array($body) && ($body['events_received'] ?? null) === 1 && ! isset($body['error'])) {
            return new DeliveryResult('api_accepted');
        }
        $retry = $response->status() === 429 || $response->serverError() || (is_array($body) && ($body['error']['is_transient'] ?? false) === true);

        return new DeliveryResult($retry ? 'retry' : 'failed', $retry ? 'META_RETRYABLE' : 'META_REJECTED');
    }
}
