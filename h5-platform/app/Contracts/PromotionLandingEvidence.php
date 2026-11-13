<?php

namespace App\Contracts;

/** Internal successful-HTML-GET handoff; never grants consent or performs external I/O. */
interface PromotionLandingEvidence
{
    public function record(string $dramaId): void;
}
