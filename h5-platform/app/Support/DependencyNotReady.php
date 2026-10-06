<?php

namespace App\Support;

class DependencyNotReady extends ApiProblem
{
    public function __construct(string $module)
    {
        parent::__construct('DEPENDENCY_NOT_READY', 503, $module.' 模块未就绪');
    }
}
