<?php

namespace App\Data;

final readonly class HomePage extends ContractData
{
    /**
     * @param list<Category> $categories
     * @param list<HomeBanner> $banners
     * @param list<DramaCard> $recommendations
     */
    public function __construct(
        public string $locale,
        public array $categories,
        public array $banners,
        public array $recommendations
    ) {}

    protected static function types(): array
    {
        return ['categories' => [Category::class], 'banners' => [HomeBanner::class], 'recommendations' => [DramaCard::class]];
    }
}
