<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'invitation_id',
        'package_id',
        'voucher_id',
        'voucher_code',
        'subtotal_sen',
        'discount_sen',
        'reference',
        'amount_sen',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'payment_url',
        'paid_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
