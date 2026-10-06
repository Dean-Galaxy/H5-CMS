<?php

namespace App\Support;

use RuntimeException;

class ApiProblem extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
