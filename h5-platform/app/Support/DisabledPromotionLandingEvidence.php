<?php

namespace App\Support;

use App\Contracts\PromotionLandingEvidence;

final class DisabledPromotionLandingEvidence implements PromotionLandingEvidence
{
    public function record(string $dramaId): void {}
}
