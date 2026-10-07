<?php

namespace App\Modules\Content\Models;

final class Grant extends ContentRecord
{
    protected $table = 'content_grants';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    public function session()
    {
        return $this->belongsTo(PlaySession::class, 'session_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
