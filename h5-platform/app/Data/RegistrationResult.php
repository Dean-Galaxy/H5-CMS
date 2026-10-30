<?php

namespace App\Data;

use App\Enums\RegistrationFailure;
use App\Enums\RegistrationResultKind;
use InvalidArgumentException;

/** No viewer, email, password, session, source, order or arbitrary properties. */
final readonly class RegistrationResult extends ContractData
{
    public function __construct(
        public RegistrationResultKind $event_name,
        public ?RegistrationFailure $failure = null,
    ) {
        if (($event_name === RegistrationResultKind::Failed) !== ($failure !== null)) {
            throw new InvalidArgumentException('Registration failure must match the failed result only.');
        }
    }

    protected static function types(): array
    {
        return ['event_name' => RegistrationResultKind::class, 'failure' => RegistrationFailure::class];
    }
}
