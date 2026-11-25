<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotobookLike extends Model
{
    protected $fillable = ['photobook_photo_id', 'fingerprint'];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(PhotobookPhoto::class, 'photobook_photo_id');
    }
}
