<?php

namespace App\Data;

use App\Enums\Role;

final readonly class AssignableStaff extends ContractData
{
    public function __construct(
        public string $id,
        public string $name,
        public Role $role,
        public bool $active
    ) {}

    protected static function types(): array
    {
        return ['role' => Role::class];
    }
}
