<?php

namespace App\Contracts;

use App\Data\Progress;
use App\Data\ProgressInput;
use App\Data\ViewerIdentity;

interface ProgressService
{
    public function progress(ViewerIdentity $viewer, string $dramaId): ?Progress;

    public function saveProgress(ViewerIdentity $viewer, string $dramaId, ProgressInput $input): Progress;
}
