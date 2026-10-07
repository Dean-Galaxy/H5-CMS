<?php

namespace App\Modules\Content\Models;

final class CategoryRecord extends ContentRecord
{
    protected $table = 'content_categories';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
