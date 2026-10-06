<?php

namespace App\Data;

final readonly class CategoryList extends ContractData
{
    /** @param list<Category> $items */
    public function __construct(
        public string $locale,
        public array $items
    ) {}

    protected static function types(): array
    {
        return ['items' => [Category::class]];
    }
}
