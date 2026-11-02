<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function stats(): JsonResponse
    {
        $paid = Order::query()->where('status', 'paid');

        return response()->json(['data' => [
            'customers_total' => Customer::count(),
            'customers_marketing' => Customer::where('marketing_consent', true)->count(),
            'customers_with_account' => Customer::whereNotNull('user_id')->count(),
            'invitations_draft' => Invitation::where('status', 'draft')->count(),
            'invitations_pending_payment' => Invitation::where('status', 'pending_payment')->count(),
            'invitations_published' => Invitation::where('status', 'published')->count(),
            'orders_paid' => (clone $paid)->count(),
            'orders_pending' => Order::where('status', 'pending')->count(),
            'revenue_sen' => (int) (clone $paid)->sum('amount_sen'),
            'revenue_30d_sen' => (int) (clone $paid)->where('paid_at', '>=', now()->subDays(30))->sum('amount_sen'),
            'recent_orders' => $this->orderQuery()->latest('id')->limit(5)->get()->map(fn (Order $o) => $this->orderRow($o))->values(),
        ]]);
    }

    public function customers(Request $request): JsonResponse
    {
        $page = $this->customerQuery($request)->latest('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Customer $c) => $this->customerRow($c))->values(),
            'meta' => $this->meta($page),
        ]);
    }

    public function exportCustomers(Request $request): StreamedResponse
    {
        $query = $this->customerQuery($request)->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nama Penuh', 'Emel', 'No Telefon', 'Persetujuan Pemasaran', 'Sumber', 'Bil Pesanan Berbayar', 'Jumlah Belanja (RM)', 'Tarikh Daftar']);
            $query->chunkById(500, function ($customers) use ($out): void {
                foreach ($customers as $c) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $c->full_name,
                        $c->email,
                        $c->phone,
                        $c->marketing_consent ? 'Ya' : 'Tidak',
                        $c->source,
                        (string) $c->paid_orders_count,
                        number_format(((int) $c->total_spent_sen) / 100, 2, '.', ''),
                        $c->created_at?->format('Y-m-d H:i'),
                    ]));
                }
            });
            fclose($out);
        }, 'pelanggan-jemputanku.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = $this->orderQuery()->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $like = '%'.$this->escape($request->string('q')).'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)));
        }
        $page = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => $this->orderRow($o))->values(),
            'meta' => $this->meta($page),
        ]);
    }

    public function order(Order $order): JsonResponse
    {
        $order->load(['customer', 'package', 'invitation.template']);

        return response()->json(['data' => $this->orderRow($order) + ['payment_url' => $order->payment_url]]);
    }

    public function syncOrder(Order $order, OrderPaymentService $payments): JsonResponse
    {
        $order = $payments->sync($order)->load(['customer', 'package', 'invitation.template']);

        return response()->json(['data' => $this->orderRow($order)]);
    }

    public function invitations(Request $request): JsonResponse
    {
        $query = Invitation::query()->with(['template', 'customer:id,full_name,email,phone', 'package:id,slug,name', 'orders'])->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $like = '%'.$this->escape($request->string('q')).'%';
            $query->where(fn ($q) => $q->where('slug', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $like)->orWhere('email', 'like', $like)));
        }
        $page = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Invitation $i) => $this->invitationRow($i))->values(),
            'meta' => $this->meta($page),
        ]);
    }

    public function invitation(Invitation $invitation): JsonResponse
    {
        $invitation->load(['template', 'customer', 'package', 'orders']);

        return response()->json(['data' => $this->invitationRow($invitation)]);
    }

    private function customerQuery(Request $request)
    {
        $query = Customer::query()
            ->withCount(['orders', 'orders as paid_orders_count' => fn ($q) => $q->where('status', 'paid'), 'invitations'])
            ->withSum(['orders as total_spent_sen' => fn ($q) => $q->where('status', 'paid')], 'amount_sen')
            ->withMax('orders as last_order_at', 'created_at');

        if ($request->boolean('consent')) {
            $query->where('marketing_consent', true);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->filled('q')) {
            $like = '%'.$this->escape($request->string('q')).'%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
        }

        return $query;
    }

    private function orderQuery()
    {
        return Order::query()->with(['customer:id,full_name,email,phone', 'package:id,slug,name', 'invitation:id,slug,status,content,template_id', 'invitation.template:id,name']);
    }

    private function customerRow(Customer $c): array
    {
        return [
            'id' => $c->id,
            'full_name' => $c->full_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'marketing_consent' => $c->marketing_consent,
            'marketing_consent_at' => $c->marketing_consent_at,
            'source' => $c->source,
            'has_account' => $c->user_id !== null,
            'orders_count' => (int) $c->orders_count,
            'paid_orders_count' => (int) $c->paid_orders_count,
            'total_spent_sen' => (int) $c->total_spent_sen,
            'invitations_count' => (int) $c->invitations_count,
            'last_order_at' => $c->last_order_at,
            'created_at' => $c->created_at,
        ];
    }

    private function orderRow(Order $o): array
    {
        return [
            'id' => $o->id,
            'reference' => $o->reference,
            'status' => $o->status,
            'amount_sen' => $o->amount_sen,
            'discount_sen' => (int) $o->discount_sen,
            'voucher_code' => $o->voucher_code,
            'package' => $o->package?->only(['slug', 'name']),
            'customer' => $o->customer?->only(['id', 'full_name', 'email', 'phone']),
            'invitation' => $o->invitation ? [
                'id' => $o->invitation->id,
                'slug' => $o->invitation->slug,
                'status' => $o->invitation->status,
                'title' => InvitationSupport::title($o->invitation),
                'template_name' => $o->invitation->template?->name,
            ] : null,
            'provider_reference' => $o->provider_reference,
            'paid_at' => $o->paid_at,
            'created_at' => $o->created_at,
        ];
    }

    private function invitationRow(Invitation $i): array
    {
        $content = $i->content ?? [];
        $hasQr = filled($content['donationQr'] ?? null);
        unset($content['donationQr']);
        unset($content['design']['background']);
        if (isset($content['design']) && $content['design'] === []) {
            unset($content['design']);
        }
        $order = $i->orders->firstWhere('status', 'paid') ?? $i->orders->sortByDesc('id')->first();

        return [
            'id' => $i->id,
            'slug' => $i->slug,
            'status' => $i->status,
            'title' => InvitationSupport::title($i),
            'public_path' => InvitationSupport::publicPath($i),
            'template' => InvitationSupport::template($i),
            'package' => $i->package?->only(['slug', 'name']),
            'customer' => $i->customer?->only(['id', 'full_name', 'email', 'phone']),
            'order' => $order ? ['reference' => $order->reference, 'status' => $order->status, 'amount_sen' => $order->amount_sen] : null,
            'content' => $content,
            'has_donation_qr' => $hasQr,
            'expires_at' => $i->expires_at,
            'created_at' => $i->created_at,
        ];
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 20)));
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function safeCell(?string $value): ?string
    {
        return $value !== null && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
