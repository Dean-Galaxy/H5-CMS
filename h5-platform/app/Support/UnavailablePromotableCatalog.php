<?php

namespace App\Support;

use App\Contracts\PromotableCatalog;
use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;

final class UnavailablePromotableCatalog implements PromotableCatalog
{
    public function promotable(?string $cursor = null, int $limit = 20, string $locale = 'en'): PromotableDramaPage
    {
        throw new DependencyNotReady('Content / Promotable catalog');
    }

    public function promotableDrama(string $dramaId, string $locale = 'en'): PromotableDrama
    {
        throw new DependencyNotReady('Content / Promotable catalog');
    }
}
