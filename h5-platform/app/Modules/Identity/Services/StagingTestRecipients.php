<?php

namespace App\Modules\Identity\Services;

use App\Support\ApiProblem;
use App\Support\RuntimeEnvironmentGuard;
use Illuminate\Mail\Events\MessageSending;

final class StagingTestRecipients
{
    public function assertAllowedAddress(string $address): void
    {
        if ($this->active()) {
            $this->check([$address]);
        }
    }

    public function assertMessageRecipients(MessageSending $event): void
    {
        if ($this->active()) {
            $addresses = [...$event->message->getTo(), ...$event->message->getCc(), ...$event->message->getBcc()];
            $this->check(array_map(fn ($address) => $address->getAddress(), $addresses));
        }
    }

    public function assertConfigured(): void
    {
        $this->configuredAddresses();
    }

    private function active(): bool
    {
        return app()->environment('staging') && config('h5.controlled_staging') === true
            && config('h5.acquisition_staging_test') === true && config('mail.default') === 'smtp';
    }

    private function configuredAddresses(): array
    {
        if (! $this->active() || config('mail.mailers.smtp.transport') !== 'smtp') {
            $this->deny();
        }
        app(RuntimeEnvironmentGuard::class)->controlledAcquisitionTestOrigin();
        $allowed = config('identity.staging_test_recipients', []);
        if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === []) {
            $this->deny();
        }
        $normalized = [];
        foreach ($allowed as $address) {
            if (! is_string($address) || ! filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                $this->deny();
            }
            $normalized[] = mb_strtolower(trim($address));
        }

        return $normalized;
    }

    private function check(array $addresses): void
    {
        $allowed = $this->configuredAddresses();
        if (count($addresses) !== 1 || ! in_array(mb_strtolower(trim($addresses[0])), $allowed, true)) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw new ApiProblem('NOT_AUTHORIZED', 403, '测试收件范围不允许此操作');
    }
}
