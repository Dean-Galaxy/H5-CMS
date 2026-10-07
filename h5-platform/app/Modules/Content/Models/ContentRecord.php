<?php

namespace App\Modules\Content\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class ContentRecord extends Model
{
    use HasUuids;

    protected $guarded = [];
}
