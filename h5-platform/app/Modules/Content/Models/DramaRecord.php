<?php

namespace App\Modules\Content\Models;

final class DramaRecord extends ContentRecord
{
    protected $table = 'content_dramas';

    protected function casts(): array
    {
        return ['regions' => 'array', 'free_overridden' => 'boolean', 'recommended' => 'boolean', 'license_from' => 'immutable_datetime', 'license_until' => 'immutable_datetime'];
    }

    public function episodes()
    {
        return $this->hasMany(EpisodeRecord::class, 'drama_id')->orderBy('number');
    }

    public function cover()
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id');
    }

    public function plan()
    {
        return $this->hasOne(Plan::class, 'drama_id');
    }

    public function categories()
    {
        return $this->belongsToMany(CategoryRecord::class, 'content_drama_categories', 'drama_id', 'category_id');
    }
}
