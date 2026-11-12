<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Billplz\BillplzClient;
use App\Services\Chip\ChipClient;
use App\Services\OrderPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

class PaymentCallbackController extends Controller
{
    /**
     * Server-to-server webhook (BILLPLZ_CALLBACK_URL). Billplz cannot reach localhost, so locally the
     * status page polling (OrderPaymentService::sync) is what actually marks orders paid.
     */
    public function billplz(Request $request, BillplzClient $billplz, OrderPaymentService $payments): Response
    {
        $params = $request->post();

        if (! $billplz->verifySignature($params)) {
            return response('Invalid signature', 403);
        }

        $billId = (string) ($params['id'] ?? '');
        $paid = filter_var($params['paid'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'paid' : 'unpaid';

        try {
            DB::table('payment_webhook_events')->insert([
                'provider' => 'billplz',
                'event_reference' => "billplz:{$billId}:{$paid}",
                'processing_status' => 'received',
                'payload' => json_encode(collect($params)->except('x_signature')->all()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('OK');
        }

        $order = Order::query()->where('provider', 'billplz')->where('provider_reference', $billId)->first();
        if ($order) {
            $payments->settle($order, $params);
        }

        DB::table('payment_webhook_events')
            ->where('event_reference', "billplz:{$billId}:{$paid}")
            ->update(['processing_status' => $order ? 'processed' : 'unmatched', 'processed_at' => now()]);

        return response('OK');
    }

    /** Handles the signed redirect Billplz sends the customer back with (billplz[id], billplz[paid], billplz[x_signature]). */
    public function billplzRedirect(Request $request, BillplzClient $billplz, OrderPaymentService $payments): JsonResponse
    {
        $bill = $request->input('billplz');
        if (! is_array($bill)) {
            return response()->json(['message' => 'Respons Billplz tidak lengkap.'], 422);
        }

        $params = ['billplz' => collect($bill)->except('x_signature')->all(), 'x_signature' => $bill['x_signature'] ?? null];
        if (! $billplz->verifySignature($params)) {
            return response()->json(['message' => 'Tandatangan Billplz tidak sah.'], 403);
        }

        $order = Order::query()
            ->where('provider', 'billplz')
            ->where('provider_reference', (string) ($bill['id'] ?? ''))
            ->first();
        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemui.'], 404);
        }

        $order = $payments->sync($order);

        return response()->json(['status' => $order->status, 'reference' => $order->reference]);
    }

    public function chip(Request $request, ChipClient $chip, OrderPaymentService $payments): Response
    {
        $rawBody = $request->getContent();
        if (! $chip->verifyCallbackSignature($rawBody, $request->header('X-Signature'))) {
            return response('Invalid signature', 403);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('Invalid JSON', 400);
        }

        if (! is_array($payload)) {
            return response('Invalid JSON', 400);
        }

        $purchaseId = $payload['id'] ?? null;
        if (! is_string($purchaseId) || $purchaseId === '') {
            return response('Missing purchase id', 422);
        }

        $purchase = $chip->getPurchase($purchaseId);
        $order = Order::query()
            ->where('provider', 'chip')
            ->where('provider_reference', $purchaseId)
            ->first();

        if (! $order) {
            return response('Order not found', 404);
        }

        $eventType = is_string($payload['event_type'] ?? null) ? $payload['event_type'] : 'purchase.paid';
        $eventReference = "chip:{$purchaseId}:{$eventType}";

        try {
            DB::table('payment_webhook_events')->insert([
                'provider' => 'chip',
                'event_reference' => $eventReference,
                'processing_status' => 'received',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('OK');
        }

        $settled = $payments->settleChip($order, $purchase);
        DB::table('payment_webhook_events')
            ->where('event_reference', $eventReference)
            ->update(['processing_status' => $settled ? 'processed' : 'rejected', 'processed_at' => now()]);

        if (! $settled) {
            Log::warning('CHIP callback did not settle order', ['order' => $order->reference, 'purchase_id' => $purchaseId]);
        }

        return response('OK');
    }
}
