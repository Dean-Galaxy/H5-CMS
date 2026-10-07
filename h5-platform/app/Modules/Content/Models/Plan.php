<?php

namespace App\Modules\Content\Models;

final class Plan extends ContentRecord
{
    protected $table = 'content_plans';

    protected function casts(): array
    {
        return ['launch_at' => 'immutable_datetime', 'next_at' => 'immutable_datetime'];
    }

    public function drama()
    {
        return $this->belongsTo(DramaRecord::class, 'drama_id');
    }
}
