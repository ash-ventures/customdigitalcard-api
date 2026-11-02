<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Billplz\BillplzClient;
use App\Services\OrderPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;

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

        $order = Order::query()->where('provider_reference', $billId)->first();
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

        $order = Order::query()->where('provider_reference', (string) ($bill['id'] ?? ''))->first();
        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemui.'], 404);
        }

        $order = $payments->sync($order);

        return response()->json(['status' => $order->status, 'reference' => $order->reference]);
    }
}
