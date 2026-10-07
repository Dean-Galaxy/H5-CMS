<?php

namespace App\Modules\Content\Models;

final class Banner extends ContentRecord
{
    protected $table = 'content_banners';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function drama()
    {
        return $this->belongsTo(DramaRecord::class, 'drama_id');
    }

    public function image()
    {
        return $this->belongsTo(Asset::class, 'image_asset_id');
    }
}
