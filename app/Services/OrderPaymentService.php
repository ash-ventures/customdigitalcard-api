<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Billplz\BillplzClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderPaymentService
{
    public function __construct(private BillplzClient $billplz)
    {
    }

    /**
     * Re-checks a pending order against Billplz; failures leave the order pending.
     * Called on every GET /orders/{reference} (the status page polls this every 3s).
     * DEBUG: errors are written to storage/logs/laravel.log; "Billplz getBill" debug lines show what Billplz returned.
     */
    public function sync(Order $order): Order
    {
        // Throttle: clients poll often, but Billplz is queried at most once per 2s per order.
        $recentlySynced = $order->last_synced_at && $order->last_synced_at->gt(now()->subSeconds(2));

        if ($order->status === 'pending' && $order->provider_reference && ! $recentlySynced) {
            try {
                $this->settle($order, $this->billplz->getBill($order->provider_reference));
            } catch (Throwable $exception) {
                report($exception);
            }
            $order->refresh()->forceFill(['last_synced_at' => now()])->save();
        }

        return $order->refresh();
    }

    /** Publishes an order whose total is fully covered by a voucher (no gateway involved). */
    public function settleFree(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'paid' || $order->amount_sen !== 0) {
                return;
            }
            $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->publish($order);
        });
    }

    public function settle(Order $order, array $bill): bool
    {
        return DB::transaction(function () use ($order, $bill): bool {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                return true;
            }

            // A bill deleted at Billplz can never be paid: close the order so the customer sees "gagal".
            if (($bill['state'] ?? null) === 'deleted' && $order->status === 'pending') {
                $order->forceFill(['status' => 'cancelled'])->save();
                Log::info('Billplz bill deleted, order cancelled', ['order' => $order->reference]);

                return false;
            }

            // Paid means Billplz reports paid=true AND state=paid, for our bill, in our collection.
            $paid = filter_var($bill['paid'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && (($bill['state'] ?? 'paid') === 'paid')
                && (($bill['collection_id'] ?? config('services.billplz.collection_id')) === config('services.billplz.collection_id'));
            $sameBill = ($bill['id'] ?? null) === $order->provider_reference;
            $amountOk = (int) ($bill['amount'] ?? -1) === $order->amount_sen
                && (! isset($bill['paid_amount']) || (int) $bill['paid_amount'] >= $order->amount_sen);

            // Order is only marked paid when Billplz says paid, the bill id matches and the amount matches.
            if (! $paid || ! $sameBill || ! $amountOk) {
                Log::info('Billplz settle skipped', ['order' => $order->reference, 'paid' => $paid, 'sameBill' => $sameBill, 'amountOk' => $amountOk]);

                return false;
            }

            $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->publish($order);

            return true;
        });
    }

    private function publish(Order $order): void
    {
        $invitation = $order->invitation()->firstOrFail();
        $package = $order->package()->firstOrFail();
        $benefits = $order->package_snapshot ?? $package->only(['validity_days_after_event', 'rsvp_enabled', 'guestbook_enabled']);
        $date = $invitation->content['date'] ?? null;

        try {
            $expires = $date
                ? CarbonImmutable::createFromFormat('Y-m-d', $date, 'Asia/Kuala_Lumpur')->addDays($benefits['validity_days_after_event'])->endOfDay()
                : now()->addDays(180);
        } catch (Throwable) {
            $expires = now()->addDays(180);
        }

        $invitation->forceFill([
            'status' => 'published',
            'package_id' => $package->id,
            'published_at' => $invitation->published_at ?? now(),
            'expires_at' => $expires,
            'rsvp_enabled' => (bool) ($invitation->content['rsvpEnabled'] ?? false) && $benefits['rsvp_enabled'],
            'guestbook_enabled' => (bool) $benefits['guestbook_enabled'],
        ])->save();
    }
}
