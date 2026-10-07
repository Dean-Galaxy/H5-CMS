<?php

namespace App\Modules\Content\Models;

final class EpisodeRecord extends ContentRecord
{
    protected $table = 'content_episodes';

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    public function drama()
    {
        return $this->belongsTo(DramaRecord::class, 'drama_id');
    }

    public function video()
    {
        return $this->belongsTo(Asset::class, 'video_asset_id');
    }

    public function subtitle()
    {
        return $this->belongsTo(Asset::class, 'subtitle_asset_id');
    }
}
