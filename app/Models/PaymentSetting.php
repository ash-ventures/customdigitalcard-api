<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id',
        'provider',
        'manual_qr_path',
        'bank_name',
        'bank_type',
        'account_holder',
        'account_number',
        'whatsapp',
        'email',
        'updated_by',
    ];

    public static function current(): ?self
    {
        return static::query()->find(1);
    }

    public function manualInstructions(): array
    {
        return [
            'qr_url' => $this->manual_qr_path
                ? '/api/v1/payment-settings/qr/'.rawurlencode(basename($this->manual_qr_path))
                : null,
            'bank_name' => $this->bank_name,
            'bank_type' => $this->bank_type,
            'account_holder' => $this->account_holder,
            'account_number' => $this->account_number,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
        ];
    }
}
