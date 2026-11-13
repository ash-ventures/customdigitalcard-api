<?php

namespace App\Services;

use App\Models\Order;
use App\Support\InvitationSupport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramPaymentNotifier
{
    public function notify(Order $order, string $outcome): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            Log::warning('Telegram payment notification skipped because Telegram is not configured', [
                'order' => $order->reference,
                'outcome' => $outcome,
            ]);

            return;
        }

        $order->loadMissing(['customer', 'package', 'invitation']);
        $voucher = $order->voucher_code
            ? $order->voucher_code.' (discount RM '.number_format($order->discount_sen / 100, 2).')'
            : 'None';
        $successful = $outcome === 'success';
        $recordedAt = ($successful ? $order->paid_at : $order->updated_at)
            ?? $order->created_at
            ?? now();
        $recordedAtText = $recordedAt->copy()
            ->timezone('Asia/Kuala_Lumpur')
            ->format('d M Y, h:i:s A');
        $customerName = $order->customer?->full_name ?? 'Unavailable';
        $customerPhone = $order->customer?->phone ?? 'Unavailable';
        $customerEmail = $order->customer?->email ?? 'Unavailable';
        $packageName = $order->package?->name ?? data_get($order->package_snapshot, 'name', 'Unavailable');
        $total = 'RM '.number_format($order->amount_sen / 100, 2);
        $invitation = $order->invitation;
        $cardPath = $invitation
            ? (InvitationSupport::publicPath($invitation) ?? InvitationSupport::supportPath($invitation))
            : null;
        $frontendUrl = rtrim((string) config('services.payments.frontend_url'), '/');
        $cardUrl = $cardPath ? $frontendUrl.$cardPath : 'Unavailable';
        $gatewayReference = $order->provider_reference ?: 'Not created';
        $whatsappUrl = $this->whatsappUrl($customerPhone, implode("\n", [
            'Hello '.$customerName.', this is about your JemputanKu payment.',
            'Payment status: '.($successful ? 'Success' : 'Failed'),
            'Full name: '.$customerName,
            'Phone: '.$customerPhone,
            'Email: '.$customerEmail,
            'Package: '.$packageName,
            'Total: '.$total,
            'Voucher: '.$voucher,
            'Order reference: '.$order->reference,
            'Payment recorded at: '.$recordedAtText,
        ]));

        $message = implode("\n", [
            $successful ? 'PAYMENT SUCCESS' : 'PAYMENT FAILED',
            'Name: '.$customerName,
            'Phone: '.$customerPhone,
            'Email: '.$customerEmail,
            'Package: '.$packageName,
            'Total: '.$total,
            'Voucher: '.$voucher,
            'Order reference: '.$order->reference,
            'Payment recorded at: '.$recordedAtText,
            'Gateway reference: '.$gatewayReference,
            'Card link: '.$cardUrl,
            'WhatsApp: '.($whatsappUrl ?? 'Unavailable'),
        ]);

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful() || ! $response->json('ok')) {
                Log::error('Telegram payment notification was rejected', [
                    'order' => $order->reference,
                    'outcome' => $outcome,
                    'http_status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Telegram payment notification request failed', [
                'order' => $order->reference,
                'outcome' => $outcome,
                'exception' => $exception::class,
            ]);
        }
    }

    private function whatsappUrl(string $phone, string $message): ?string
    {
        $number = preg_replace('/\D+/', '', $phone);
        if (! is_string($number) || $number === '') {
            return null;
        }

        if (str_starts_with($number, '0')) {
            $number = '60'.substr($number, 1);
        }

        if (strlen($number) < 8 || strlen($number) > 15) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }
}
