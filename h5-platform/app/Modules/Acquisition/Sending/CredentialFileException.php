<?php

namespace App\Modules\Acquisition\Sending;

final class CredentialFileException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('私密配置操作未完成；未输出凭据或路径。');
    }
}
