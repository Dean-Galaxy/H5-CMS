<?php

namespace App\Modules\Content\Models;

final class Publication extends ContentRecord
{
    protected $table = 'content_publications';

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime', 'episode_numbers' => 'array'];
    }

    public $timestamps = false;
}
