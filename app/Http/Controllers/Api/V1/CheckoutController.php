<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Package;
use App\Models\Template;
use App\Models\Voucher;
use App\Services\Billplz\BillplzClient;
use App\Services\OrderPaymentService;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class CheckoutController extends Controller
{
    public function store(Request $request, BillplzClient $billplz, OrderPaymentService $payments): JsonResponse
    {
        $user = auth('sanctum')->user();
        $needsCustomer = Rule::requiredIf(! $user);

        $validated = $request->validate([
            'template' => ['required', 'exists:templates,slug'],
            'package' => ['required', 'exists:packages,slug'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
            'invitation_id' => ['nullable', 'integer'],
            'customer' => [$needsCustomer, 'array'],
            'customer.name' => [$needsCustomer, 'string', 'max:120'],
            'customer.phone' => [$needsCustomer, 'string', 'regex:/^\+?[0-9\s-]{8,16}$/'],
            'customer.email' => [$needsCustomer, 'email:rfc', 'max:255'],
            'customer.marketing_consent' => ['nullable', 'boolean'],
        ] + InvitationSupport::contentRules());

        $template = Template::where('slug', $validated['template'])->where('is_active', true)->first();
        $package = Package::where('slug', $validated['package'])->where('is_active', true)->first();

        if (! $template || ! $package) {
            return response()->json(['message' => 'Tema atau pakej tidak tersedia.'], 422);
        }
        if ($template->is_premium && ! $package->premium_templates) {
            return response()->json([
                'message' => 'Tema premium memerlukan pakej Istimewa atau Seri.',
                'errors' => ['package' => ['Tema premium memerlukan pakej Istimewa atau Seri.']],
            ], 422);
        }
        $details = $validated['customer'] ?? [];
        $content = $validated['content'];
        $email = $details['email'] ?? $user?->email;

        $voucher = null;
        $discount = 0;
        $total = (int) $package->price_sen;
        if (filled($validated['voucher_code'] ?? null)) {
            $voucher = Voucher::where('code', Voucher::normalise($validated['voucher_code']))->first();
            try {
                if (! $voucher) {
                    throw new \InvalidArgumentException('Kod baucar tidak sah.');
                }
                ['discount_sen' => $discount, 'total_sen' => $total] = $voucher->apply($package, $email);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => ['voucher_code' => [$exception->getMessage()]]], 422);
            }
        }

        $free = $total === 0;
        if (! $free && ! $billplz->configured()) {
            return response()->json(['message' => 'Pembayaran belum dikonfigurasi.'], 503);
        }

        [$order, $customer] = DB::transaction(function () use ($user, $details, $validated, $template, $package, $content, $voucher, $discount, $total) {
            $customer = Customer::capture(
                $details['name'] ?? $user->name,
                $details['email'] ?? $user->email,
                $details['phone'] ?? $user->phone,
                (bool) ($details['marketing_consent'] ?? false),
                'checkout',
                $user,
            );

            $invitation = null;
            if ($user && ! empty($validated['invitation_id'])) {
                $invitation = $user->invitations()
                    ->where('status', '!=', 'published')
                    ->findOrFail($validated['invitation_id']);
            }

            $attributes = [
                'template_id' => $template->id,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'status' => 'pending_payment',
                'content' => $content,
                'rsvp_enabled' => false,
                'rsvp_deadline' => InvitationSupport::rsvpDeadline($content['rsvpDeadline'] ?? null),
            ];

            if ($invitation) {
                if (str_starts_with($invitation->slug, 'draft-')) {
                    $attributes['slug'] = InvitationSupport::finalSlug($content);
                }
                $invitation->update($attributes);
            } else {
                $invitation = Invitation::create($attributes + [
                    'user_id' => $user?->id,
                    'slug' => InvitationSupport::finalSlug($content),
                ]);
            }

            $order = Order::create([
                'user_id' => $user?->id,
                'customer_id' => $customer->id,
                'invitation_id' => $invitation->id,
                'package_id' => $package->id,
                'reference' => 'JK-'.Str::upper(Str::random(12)),
                'voucher_id' => $voucher?->id,
                'voucher_code' => $voucher?->code,
                'subtotal_sen' => $package->price_sen,
                'discount_sen' => $discount,
                'amount_sen' => $total,
                'currency' => $package->currency,
                'status' => 'pending',
                'provider' => $total === 0 ? 'voucher' : 'billplz',
            ]);

            return [$order, $customer];
        });

        if ($free) {
            $payments->settleFree($order);

            return response()->json(['data' => [
                'order_reference' => $order->reference,
                'payment_url' => null,
                'amount_sen' => 0,
                'status' => 'paid',
            ]], 201);
        }

        try {
            $bill = $billplz->createBill($order, $customer, 'JemputanKu - Pakej '.$package->name);
        } catch (Throwable $exception) {
            report($exception);
            $order->update(['status' => 'failed']);

            return response()->json(['message' => 'Gerbang pembayaran tidak dapat dihubungi. Sila cuba lagi.'], 502);
        }

        $order->update(['provider_reference' => $bill['id'], 'payment_url' => $bill['url']]);

        return response()->json(['data' => [
            'order_reference' => $order->reference,
            'payment_url' => $order->payment_url,
            'amount_sen' => $order->amount_sen,
            'status' => 'pending',
        ]], 201);
    }

    public function status(string $reference, OrderPaymentService $payments): JsonResponse
    {
        $order = Order::query()->where('reference', $reference)->with(['package:id,slug,name', 'invitation'])->firstOrFail();
        $order = $payments->sync($order)->load('invitation');
        $invitation = $order->invitation;

        return response()->json(['data' => [
            'reference' => $order->reference,
            'status' => $order->status,
            'amount_sen' => $order->amount_sen,
            'discount_sen' => (int) $order->discount_sen,
            'voucher_code' => $order->voucher_code,
            'package' => $order->package->only(['slug', 'name']),
            'paid_at' => $order->paid_at,
            'payment_url' => $order->status === 'pending' ? $order->payment_url : null,
            'invitation' => [
                'slug' => $invitation->slug,
                'public_path' => $order->status === 'paid' ? InvitationSupport::publicPath($invitation) : null,
                'title' => InvitationSupport::title($invitation),
            ],
        ]]);
    }
}
