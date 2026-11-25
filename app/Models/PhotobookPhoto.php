<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotobookPhoto extends Model
{
    protected $fillable = [
        'invitation_id',
        'sender_name',
        'relationship',
        'note',
        'image_path',
        'mime',
        'width',
        'height',
        'likes_count',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PhotobookLike::class);
    }
}
