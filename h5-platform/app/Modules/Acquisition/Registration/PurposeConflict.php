<?php

namespace App\Modules\Acquisition\Registration;

final class PurposeConflict extends \RuntimeException
{
    public function __construct(public readonly string $problemCode, public readonly array $state)
    {
        parent::__construct($problemCode);
    }
}
