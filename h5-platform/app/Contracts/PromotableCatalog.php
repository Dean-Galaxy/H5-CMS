<?php

namespace App\Contracts;

use App\Data\PromotableDrama;
use App\Data\PromotableDramaPage;

interface PromotableCatalog
{
    /** Staff MFA + PublishedCatalogRead. US campaign inventory, not the viewer's region; limit 1..50. */
    public function promotable(?string $cursor = null, int $limit = 20, string $locale = 'en'): PromotableDramaPage;

    /** Revalidate published episodes and the current US licence; no media or playback permission returned. */
    public function promotableDrama(string $dramaId, string $locale = 'en'): PromotableDrama;
}
