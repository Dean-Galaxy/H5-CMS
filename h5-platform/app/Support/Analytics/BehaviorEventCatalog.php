<?php

namespace App\Support\Analytics;

final class BehaviorEventCatalog
{
    public const SCHEMA_VERSION = 1;

    public const POLICY_VERSION = 'analytics-v1';

    public const BROWSER_EVENTS = [
        'page_view', 'landing_view', 'drama_view', 'play_requested', 'play_started',
        'watch_progress', 'episode_completed', 'player_startup', 'player_stall', 'player_error',
        'paywall_view', 'subscribe_click', 'registration_view', 'registration_submit',
        'verification_view', 'checkout_click', 'payment_return_view',
    ];

    public const SERVER_EVENTS = [
        'registration_succeeded', 'registration_failed', 'login_succeeded', 'login_failed',
        'email_verified', 'logout', 'checkout_created', 'checkout_failed', 'payment_pending',
        'payment_cancelled', 'payment_failed', 'payment_confirmed', 'refund_confirmed',
        'subscription_cancelled', 'subscription_resumed',
    ];
}
