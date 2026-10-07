<?php

namespace App\Data;

final readonly class AssignableStaffPage extends ContractData
{
    /** @param list<AssignableStaff> $items */
    public function __construct(
        public array $items,
        public ?string $next_cursor
    ) {}

    protected static function types(): array
    {
        return ['items' => [AssignableStaff::class]];
    }
}
