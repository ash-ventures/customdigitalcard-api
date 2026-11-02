<?php

namespace App\Services\Billplz;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BillplzClient
{
    public function configured(): bool
    {
        return filled(config('services.billplz.api_key')) && filled(config('services.billplz.collection_id'));
    }

    public function createBill(Order $order, Customer $customer, string $description): array
    {
        $payload = array_filter([
            'collection_id' => config('services.billplz.collection_id'),
            'email' => $customer->email,
            // 'mobile' => self::normaliseMobile($customer->phone),
            'name' => $customer->full_name,
            'amount' => $order->amount_sen,
            'callback_url' => config('services.billplz.callback_url'),
            'redirect_url' => rtrim(config('services.billplz.frontend_url'), '/').'/bayaran/status?ref='.$order->reference,
            'description' => mb_substr($description, 0, 200),
            // 'reference_1_label' => 'Rujukan',
            // 'reference_1' => $order->reference,
        ], fn ($value) => filled($value));

        // POST {BILLPLZ_BASE_URL}/bills ; "amount" is in sen (RM 19.00 = 1900) and comes from orders.amount_sen.
        $response = $this->http()->post('/bills', $payload);
        Log::debug('Billplz createBill', ['order' => $order->reference, 'amount' => $order->amount_sen, 'http' => $response->status(), 'bill_id' => $response->json('id')]);

        if (! $response->successful() || blank($response->json('id')) || blank($response->json('url'))) {
            throw new RuntimeException('Billplz tidak dapat mencipta bil (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    /**
     * GET {BILLPLZ_BASE_URL}/bills/{id} - returns the bill, including "paid" (bool), "state" and "paid_amount".
     * {id} is the "id" returned by createBill() and stored in orders.provider_reference.
     * DEBUG: BILLPLZ_BASE_URL must end at /api/v3 (NOT /bills), otherwise this returns 404.
     */
    public function getBill(string $id): array
    {
        $response = $this->http()->get('/bills/'.rawurlencode($id));
        Log::debug('Billplz getBill', ['id' => $id, 'http' => $response->status(), 'paid' => $response->json('paid'), 'state' => $response->json('state')]);

        if (! $response->successful()) {
            throw new RuntimeException('Billplz tidak dapat menyemak bil (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function verifySignature(array $params): bool
    {
        $key = config('services.billplz.x_signature_key');
        $given = $params['x_signature'] ?? null;
        if (blank($key) || ! is_string($given)) {
            return false;
        }
        unset($params['x_signature']);

        $pairs = [];
        $flatten = function (array $values, string $prefix = '') use (&$flatten, &$pairs): void {
            foreach ($values as $name => $value) {
                if (is_array($value)) {
                    $flatten($value, $prefix.$name);
                } else {
                    $pairs[] = $prefix.$name.$value;
                }
            }
        };
        $flatten($params);
        sort($pairs, SORT_STRING | SORT_FLAG_CASE);

        return hash_equals(hash_hmac('sha256', implode('|', $pairs), $key), $given);
    }

    public static function normaliseMobile(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '60'.substr($digits, 1);
        }
        if (! str_starts_with($digits, '60') || strlen($digits) < 10 || strlen($digits) > 13) {
            return null;
        }

        return '+'.$digits;
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.billplz.api_key'), '')
            ->acceptJson()
            ->asForm()
            ->timeout(15)
            ->baseUrl(rtrim(config('services.billplz.base_url'), '/'));
    }
}
