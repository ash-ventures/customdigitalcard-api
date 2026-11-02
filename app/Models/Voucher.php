<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    /** Billplz rejects bills below RM1.00. */
    public const MIN_BILL_SEN = 100;

    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_amount_sen', 'max_discount_sen', 'usage_limit',
        'per_customer_limit', 'package_slug', 'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Orders that count against the limits: paid, or pending and recent (reserved). */
    public function redemptions()
    {
        return $this->orders()->where(fn ($q) => $q->where('status', 'paid')
            ->orWhere(fn ($p) => $p->where('status', 'pending')->where('created_at', '>', now()->subHour())));
    }

    /**
     * @return array{discount_sen:int,total_sen:int}
     *
     * @throws \InvalidArgumentException with a user-facing message
     */
    public function apply(Package $package, ?string $email = null): array
    {
        if (! $this->is_active) {
            throw new \InvalidArgumentException('Kod baucar tidak aktif.');
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            throw new \InvalidArgumentException('Kod baucar belum boleh digunakan.');
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            throw new \InvalidArgumentException('Kod baucar telah tamat tempoh.');
        }
        if ($this->package_slug && $this->package_slug !== $package->slug) {
            throw new \InvalidArgumentException('Kod baucar tidak sah untuk pakej ini.');
        }

        $subtotal = (int) $package->price_sen;
        if ($subtotal < $this->min_amount_sen) {
            throw new \InvalidArgumentException('Jumlah pesanan tidak mencukupi untuk baucar ini.');
        }
        if ($this->usage_limit !== null && $this->redemptions()->count() >= $this->usage_limit) {
            throw new \InvalidArgumentException('Kod baucar telah habis digunakan.');
        }
        if ($this->per_customer_limit !== null && filled($email)) {
            $used = $this->redemptions()
                ->whereHas('customer', fn ($q) => $q->where('email', strtolower(trim($email))))
                ->count();
            if ($used >= $this->per_customer_limit) {
                throw new \InvalidArgumentException('Anda telah menggunakan kod baucar ini.');
            }
        }

        $discount = $this->type === 'percent'
            ? intdiv($subtotal * min($this->value, 100), 100)
            : (int) $this->value;
        if ($this->max_discount_sen !== null) {
            $discount = min($discount, (int) $this->max_discount_sen);
        }
        $discount = min($discount, $subtotal);

        $total = $subtotal - $discount;
        // A non-free bill must still meet the gateway minimum.
        if ($total > 0 && $total < self::MIN_BILL_SEN) {
            $total = min(self::MIN_BILL_SEN, $subtotal);
            $discount = $subtotal - $total;
        }

        return ['discount_sen' => $discount, 'total_sen' => $total];
    }
}
