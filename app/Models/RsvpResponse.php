<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RsvpResponse extends Model
{
    protected $fillable = [
        'invitation_id',
        'guest_name',
        'attendance',
        'event_title',
        'pax',
        'contact',
        'notes',
        'submission_key',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
