<?php

namespace App\Contracts;

use App\Data\CatalogPage;
use App\Data\Drama;

interface CatalogReader
{
    /** Public records only. limit 1..50, locale en|zh_CN. */
    public function catalog(?string $cursor = null, int $limit = 20, string $locale = 'en'): CatalogPage;

    public function drama(string $dramaId, string $locale = 'en'): Drama;
}
