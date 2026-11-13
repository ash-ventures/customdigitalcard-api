<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Billplz\BillplzClient;
use App\Services\Chip\ChipClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderPaymentService
{
    public function __construct(
        private BillplzClient $billplz,
        private ChipClient $chip,
        private TelegramPaymentNotifier $telegram,
    ) {}

    /** Re-checks a pending order with the provider saved on that order. */
    public function sync(Order $order): Order
    {
        $recentlySynced = $order->last_synced_at && $order->last_synced_at->gt(now()->subSeconds(2));

        if ($order->status === 'pending' && $order->provider_reference && ! $recentlySynced) {
            try {
                if ($order->provider === 'billplz') {
                    $this->settle($order, $this->billplz->getBill($order->provider_reference));
                } elseif ($order->provider === 'chip') {
                    $purchase = $this->chip->getPurchase($order->provider_reference);
                    if (($purchase['status'] ?? null) === 'paid') {
                        $this->settleChip($order, $purchase);
                    } elseif (in_array($purchase['status'] ?? null, ['cancelled', 'expired', 'failed', 'error'], true)) {
                        $this->cancelPending($order);
                    }
                }
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
        $settled = false;
        DB::transaction(function () use ($order, &$settled): void {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'paid' || $order->amount_sen !== 0) {
                return;
            }
            $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->publish($order);
            $settled = true;
        });

        if ($settled) {
            $this->telegram->notify($order->fresh(), 'success');
        }
    }

    public function settle(Order $order, array $bill): bool
    {
        $outcome = null;
        $settled = DB::transaction(function () use ($order, $bill, &$outcome): bool {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                return true;
            }

            // A bill deleted at Billplz can never be paid: close the order so the customer sees "gagal".
            if (($bill['state'] ?? null) === 'deleted' && $order->status === 'pending') {
                $order->forceFill(['status' => 'cancelled'])->save();
                Log::info('Billplz bill deleted, order cancelled', ['order' => $order->reference]);
                $outcome = 'failed';

                return false;
            }

            // Paid means Billplz reports paid=true AND state=paid, for our bill, in our collection.
            $paid = filter_var($bill['paid'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && (($bill['state'] ?? 'paid') === 'paid')
                && (($bill['collection_id'] ?? config('services.billplz.collection_id')) === config('services.billplz.collection_id'))
                && $order->provider === 'billplz';
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
            $outcome = 'success';

            return true;
        });

        if ($outcome) {
            $this->telegram->notify($order->fresh(), $outcome);
        }

        return $settled;
    }

    public function settleChip(Order $order, array $purchase): bool
    {
        $outcome = null;
        $settled = DB::transaction(function () use ($order, $purchase, &$outcome): bool {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                return true;
            }

            $purchaseId = $purchase['id'] ?? null;
            $status = $purchase['status'] ?? null;
            $purchaseDetails = is_array($purchase['purchase'] ?? null) ? $purchase['purchase'] : [];
            $currency = $purchaseDetails['currency'] ?? $purchase['currency'] ?? null;
            $amount = $purchaseDetails['total'] ?? $purchase['total'] ?? null;

            if (! $this->isIntegerAmount($amount) && is_array($purchaseDetails['products'] ?? null)) {
                $prices = array_map(
                    fn ($product) => is_array($product) ? ($product['price'] ?? null) : null,
                    $purchaseDetails['products'],
                );
                if ($prices !== [] && count(array_filter($prices, fn ($price) => $this->isIntegerAmount($price))) === count($prices)) {
                    $amount = array_sum(array_map('intval', $prices));
                }
            }

            $valid = $order->provider === 'chip'
                && $purchaseId === $order->provider_reference
                && $status === 'paid'
                && $this->isIntegerAmount($amount)
                && (int) $amount === $order->amount_sen
                && (! is_string($currency) || strtoupper($currency) === strtoupper($order->currency));

            if (! $valid) {
                Log::warning('CHIP payment settlement rejected', [
                    'order' => $order->reference,
                    'purchase_id_matches' => $purchaseId === $order->provider_reference,
                    'status' => $status,
                    'amount_matches' => $this->isIntegerAmount($amount) && (int) $amount === $order->amount_sen,
                    'currency' => $currency,
                ]);

                return false;
            }

            $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->publish($order);
            $outcome = 'success';

            return true;
        });

        if ($outcome) {
            $this->telegram->notify($order->fresh(), $outcome);
        }

        return $settled;
    }

    private function cancelPending(Order $order): void
    {
        $cancelled = DB::transaction(function () use ($order): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return false;
            }

            $locked->forceFill(['status' => 'cancelled'])->save();

            return true;
        });

        if ($cancelled) {
            $this->telegram->notify($order->fresh(), 'failed');
        }
    }

    private function isIntegerAmount(mixed $amount): bool
    {
        return is_int($amount) || (is_string($amount) && preg_match('/^\d+$/D', $amount) === 1);
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
