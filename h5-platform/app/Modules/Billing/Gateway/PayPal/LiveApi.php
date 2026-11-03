<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateway\PayPal;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/** Fixed production REST lane. An explicit MockHandler is the only offline test transport. */
final class LiveApi extends SandboxApi
{
    public const ORIGIN = 'https://api-m.paypal.com';

    public const ENVIRONMENT = 'live';

    public const CONFIG = 'billing.paypal_live';

    public const CALLBACK_PATH = '/webhooks/paypal/live';

    public function __construct(private ?MockHandler $mock = null)
    {
        parent::__construct($mock === null ? null : new Client(['handler' => HandlerStack::create($mock)]));
    }

    public function offlineFake(): bool
    {
        return $this->mock !== null && app()->environment('testing');
    }

    protected function runtimeAllowed(string $environment): bool
    {
        return $environment === 'production' || $this->offlineFake();
    }

    public static function productRequest(): array
    {
        return ['name' => 'H5 weekly membership', 'type' => 'DIGITAL', 'description' => 'Weekly access to available H5 membership content'];
    }
}
