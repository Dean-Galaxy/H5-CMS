<?php

namespace App\Data;

use App\Enums\Role;

final readonly class StaffIdentity
{
    public function __construct(public string $id, public string $name, public Role $role) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'role' => $this->role->value, 'scope' => $this->role->scope()];
    }
}
