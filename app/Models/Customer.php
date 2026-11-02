<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Customer extends Model
{
    protected $fillable = [
        'user_id', 'full_name', 'email', 'phone', 'marketing_consent', 'marketing_consent_at', 'source',
    ];

    protected function casts(): array
    {
        return ['marketing_consent' => 'boolean', 'marketing_consent_at' => 'datetime'];
    }

    public static function capture(string $name, string $email, ?string $phone, bool $consent, string $source, ?User $user = null): self
    {
        $customer = static::firstOrNew(['email' => Str::lower(trim($email))]);
        $customer->full_name = trim($name);
        if (filled($phone)) {
            $customer->phone = trim($phone);
        }
        if (! $customer->exists) {
            $customer->source = $source;
        }
        if ($consent && ! $customer->marketing_consent) {
            $customer->marketing_consent = true;
            $customer->marketing_consent_at = now();
        }
        if ($user && ! $customer->user_id && Str::lower($user->email) === $customer->email) {
            $customer->user_id = $user->id;
        }
        $customer->save();

        return $customer;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
