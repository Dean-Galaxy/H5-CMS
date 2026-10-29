<?php

namespace App\Modules\Acquisition\Analytics;

use App\Contracts\CatalogReader;
use App\Support\Analytics\BehaviorEventCatalog;
use Illuminate\Support\Str;

final class EventProperties
{
    public const ERROR_CODES = ['VALIDATION_FAILED', 'INVALID_CREDENTIALS', 'RATE_LIMITED', 'AUTHENTICATION_REQUIRED', 'NOT_AUTHORIZED', 'INTERNAL_ERROR', 'REQUEST_REJECTED', 'network', 'media', 'region', 'membership', 'authorization', 'timeout', 'unavailable', 'invalid',
        'validation', 'credentials', 'rate_limited', 'unknown', 'payment_pending', 'payment_cancelled', 'payment_failed'];

    public function validate(string $name, array $p, bool $browser): ?array
    {
        if (! in_array($name, $browser ? BehaviorEventCatalog::BROWSER_EVENTS : BehaviorEventCatalog::SERVER_EVENTS, true)) {
            return null;
        }
        $common = ['path', 'locale', 'page_instance_id', 'drama_id', 'episode_id', 'play_instance_id'];
        $per = match ($name) {
            'watch_progress', 'episode_completed' => ['position_seconds', 'duration_seconds', 'watched_seconds'],
            'play_requested', 'play_started' => ['position_seconds', 'duration_seconds'],
            'player_startup' => ['startup_ms'], 'player_stall' => ['stall_ms'],
            'player_error', 'registration_failed', 'login_failed', 'checkout_failed', 'payment_failed' => ['error_code'],
            default => [],
        };
        $trusted = ['viewer_id', 'order_id', 'payment_id', 'subscription_id', 'source_id', 'transaction_kind', 'amount_minor',
            'refunded_amount_minor', 'currency', 'is_test', 'occurred_at', 'fact_version'];
        if (array_diff(array_keys($p), [...$common, ...$per, ...($browser ? [] : $trusted)])) {
            return null;
        }
        foreach (['page_instance_id', 'drama_id', 'episode_id', 'play_instance_id', ...($browser ? [] : ['viewer_id', 'order_id', 'payment_id', 'subscription_id', 'source_id'])] as $id) {
            if (isset($p[$id]) && (! is_string($p[$id]) || ! Str::isUuid($p[$id]))) {
                return null;
            }
        }
        if (isset($p['path']) && (! is_string($p['path']) || ! preg_match('~^(?:/|/catalog|/membership|/billing/return|/identity/(?:login|register|account)|/dramas/[a-f0-9-]{36}(?:/episodes/[a-f0-9-]{36})?)$~D', $p['path']))) {
            return null;
        }
        if (isset($p['locale']) && ! in_array($p['locale'], ['en', 'zh_CN'], true)) {
            return null;
        }
        if (isset($p['error_code']) && ! in_array($p['error_code'], self::ERROR_CODES, true)) {
            return null;
        }
        foreach (['position_seconds' => 86400, 'duration_seconds' => 86400, 'watched_seconds' => 604800, 'startup_ms' => 600000, 'stall_ms' => 600000] as $key => $max) {
            if (isset($p[$key]) && ((! is_int($p[$key]) && ! is_float($p[$key])) || ! is_finite((float) $p[$key]) || $p[$key] < 0 || $p[$key] > $max)) {
                return null;
            }
        }
        foreach (['amount_minor', 'refunded_amount_minor', 'fact_version'] as $key) {
            if (isset($p[$key]) && (! is_int($p[$key]) || $p[$key] < ($key === 'fact_version' ? 1 : 0) || $p[$key] > 100000000000)) {
                return null;
            }
        }
        if (! $browser) {
            if (isset($p['currency']) && $p['currency'] !== 'USD') {
                return null;
            }
            if (isset($p['is_test']) && ! is_bool($p['is_test'])) {
                return null;
            }
            if (isset($p['transaction_kind']) && ! in_array($p['transaction_kind'], ['first_subscription', 'resubscription', 'renewal'], true)) {
                return null;
            }
            if (isset($p['occurred_at']) && (! is_string($p['occurred_at']) || strlen($p['occurred_at']) > 64 || ! preg_match('/^\d{4}-\d\d-\d\dT.+(?:Z|[+-]\d\d:\d\d)$/D', $p['occurred_at']))) {
                return null;
            }
            $required = match ($name) {
                'registration_succeeded', 'login_succeeded', 'email_verified' => ['viewer_id'],
                'checkout_created' => ['order_id'], 'payment_confirmed', 'refund_confirmed' => ['payment_id'],
                'subscription_cancelled', 'subscription_resumed' => ['subscription_id'], default => [],
            };
            foreach ($required as $field) {
                if (! isset($p[$field])) {
                    return null;
                }
            }
        }
        if ($browser) {
            if (! isset($p['page_instance_id'])) {
                return null;
            }
            $play = in_array($name, ['play_requested', 'play_started', 'watch_progress', 'episode_completed', 'player_startup', 'player_stall', 'player_error'], true);
            if ($play && (! isset($p['play_instance_id'], $p['drama_id'], $p['episode_id']))) {
                return null;
            }
            if ($name === 'landing_view' && ! isset($p['drama_id'])) {
                return null;
            }
            $requiredMetric = match ($name) {
                'watch_progress', 'episode_completed' => 'watched_seconds', 'player_startup' => 'startup_ms', 'player_stall' => 'stall_ms', 'player_error' => 'error_code', default => null
            };
            if ($requiredMetric && ! isset($p[$requiredMetric])) {
                return null;
            }
            if (isset($p['episode_id']) && ! isset($p['drama_id'])) {
                return null;
            }
            if (isset($p['drama_id'])) {
                $drama = app(CatalogReader::class)->drama($p['drama_id'], $p['locale'] ?? 'zh_CN');
                if (isset($p['episode_id']) && ! collect($drama->episodes)->contains(fn ($e) => $e->id === $p['episode_id'])) {
                    return null;
                }
            }
        }
        ksort($p);

        return $p;
    }
}
