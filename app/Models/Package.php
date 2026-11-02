<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'price_sen',
        'currency',
        'validity_days_after_event',
        'features',
        'gallery_limit',
        'rsvp_enabled',
        'premium_templates',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'rsvp_enabled' => 'boolean',
            'premium_templates' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
