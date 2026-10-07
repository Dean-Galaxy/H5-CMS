<?php

namespace App\Data;

final readonly class PromotableDramaPage extends ContractData
{
    /** @param list<PromotableDrama> $items */
    public function __construct(
        public array $items,
        public ?string $next_cursor
    ) {}

    protected static function types(): array
    {
        return ['items' => [PromotableDrama::class]];
    }
}
