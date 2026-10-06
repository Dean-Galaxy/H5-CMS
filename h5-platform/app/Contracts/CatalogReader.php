<?php

namespace App\Contracts;

use App\Data\CatalogPage;
use App\Data\CategoryList;
use App\Data\Drama;
use App\Data\HomePage;

interface CatalogReader
{
    /** Public records only. limit 1..50, locale en|zh_CN. */
    public function catalog(?string $cursor = null, int $limit = 20, string $locale = 'en', ?string $categoryId = null): CatalogPage;

    public function homepage(string $locale = 'en'): HomePage;

    public function categories(string $locale = 'en'): CategoryList;

    public function drama(string $dramaId, string $locale = 'en'): Drama;
}
