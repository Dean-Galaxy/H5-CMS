<?php

namespace App\Modules\Content\Models;

final class PlaySession extends ContentRecord
{
    protected $table = 'content_play_sessions';

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'until' => 'immutable_datetime'];
    }

    public $timestamps = false;

    public function episode()
    {
        return $this->belongsTo(EpisodeRecord::class, 'episode_id');
    }
}
