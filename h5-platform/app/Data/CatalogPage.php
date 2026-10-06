<?php

namespace App\Data;

final readonly class CatalogPage extends ContractData
{
    /**
     * @param  list<Drama>  $items
     */
    public function __construct(
        public array $items,
        public ?string $next_cursor
    ) {}

    protected static function types(): array
    {
        return ['items' => [Drama::class]];
    }
}
