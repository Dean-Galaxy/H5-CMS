<?php

namespace App\Modules\Content\Models;

final class Asset extends ContentRecord
{
    protected $table = 'content_assets';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'processing_until' => 'immutable_datetime'];
    }
}
