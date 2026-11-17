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
        'package_snapshot',
        'voucher_id',
        'voucher_code',
        'vendor_id',
        'vendor_code',
        'subtotal_sen',
        'discount_sen',
        'reference',
        'amount_sen',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'payment_url',
        'payment_instructions',
        'paid_at',
        'manual_confirmed_by',
        'manual_confirmed_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'manual_confirmed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'package_snapshot' => 'array',
            'payment_instructions' => 'array',
        ];
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
