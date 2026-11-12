<?php

namespace App\Services\Chip;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChipClient
{
    public function configured(): bool
    {
        return filled(config('services.chip.api_key')) && filled(config('services.chip.brand_id'));
    }

    public function createPurchase(Order $order, Customer $customer, string $description): array
    {
        $payload = [
            'brand_id' => config('services.chip.brand_id'),
            'client' => ['email' => $customer->email],
            'purchase' => [
                'products' => [[
                    'name' => mb_substr($description, 0, 200),
                    'price' => $order->amount_sen,
                ]],
            ],
            'success_redirect' => $this->redirectUrl($order),
            'failure_redirect' => $this->redirectUrl($order),
        ];

        if (filled(config('services.chip.success_callback_url'))) {
            $payload['success_callback'] = config('services.chip.success_callback_url');
        }

        $response = $this->http()->post('/purchases/', $payload);

        if (! $response->successful() || blank($response->json('id')) || blank($response->json('checkout_url'))) {
            throw new RuntimeException('CHIP tidak dapat mencipta pembayaran (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function getPurchase(string $id): array
    {
        $response = $this->http()->get('/purchases/'.rawurlencode($id).'/');

        if (! $response->successful()) {
            throw new RuntimeException('CHIP tidak dapat menyemak pembayaran (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function verifyCallbackSignature(string $body, ?string $signature): bool
    {
        if (blank($signature)) {
            return false;
        }

        $publicKey = Cache::remember('chip.collect.public_key', now()->addHours(12), function (): string {
            $response = $this->http()->get('/public_key/');
            if (! $response->successful()) {
                throw new RuntimeException('CHIP tidak dapat mendapatkan kunci awam callback (HTTP '.$response->status().').');
            }

            $key = json_decode($response->body(), true);
            if (! is_string($key) || blank($key)) {
                throw new RuntimeException('CHIP memulangkan kunci awam callback yang tidak sah.');
            }

            return $key;
        });

        $decodedSignature = base64_decode($signature, true);
        $key = openssl_pkey_get_public($publicKey);

        if ($decodedSignature === false || $key === false) {
            return false;
        }

        return openssl_verify($body, $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function redirectUrl(Order $order): string
    {
        return rtrim((string) config('services.chip.frontend_url'), '/').'/bayaran/status?ref='.rawurlencode($order->reference);
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.chip.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->baseUrl(rtrim((string) config('services.chip.base_url'), '/'));
    }
}
