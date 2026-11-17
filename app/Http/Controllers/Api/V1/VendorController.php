<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(): JsonResponse
    {
        $vendors = $this->query()->orderBy('name')->get()->map(fn (Vendor $v) => $this->row($v));

        return response()->json([
            'data' => $vendors,
            'meta' => [
                'vendors_total' => $vendors->count(),
                'vendors_active' => $vendors->where('is_active', true)->count(),
                'orders_count' => (int) $vendors->sum('orders_count'),
                'paid_orders_count' => (int) $vendors->sum('paid_orders_count'),
                'revenue_sen' => (int) $vendors->sum('revenue_sen'),
                'discount_sen' => (int) $vendors->sum('discount_sen'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if (Vendor::where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'Kod vendor sudah wujud.', 'errors' => ['code' => ['Kod vendor sudah wujud.']]], 422);
        }

        $vendor = Vendor::create($data);

        return response()->json(['data' => $this->row($this->query()->findOrFail($vendor->id))], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($this->validated($request, $vendor));

        return response()->json(['data' => $this->row($this->query()->findOrFail($vendor->id))]);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        // Orders reference the vendor for reporting, so a used vendor is deactivated instead of deleted.
        if ($vendor->orders()->exists()) {
            $vendor->update(['is_active' => false]);

            return response()->json([
                'data' => $this->row($this->query()->findOrFail($vendor->id)),
                'message' => 'Vendor mempunyai pesanan, dinyahaktifkan sahaja.',
            ]);
        }

        $vendor->vouchers()->update(['vendor_id' => null]);
        $vendor->delete();

        return response()->json(['data' => null]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Vendor> */
    private function query()
    {
        return Vendor::query()
            ->withCount('vouchers')
            ->withCount(['orders', 'orders as paid_orders_count' => fn ($q) => $q->where('status', 'paid')])
            ->withSum(['orders as revenue_sen' => fn ($q) => $q->where('status', 'paid')], 'amount_sen')
            ->withSum(['orders as discount_sen' => fn ($q) => $q->where('status', 'paid')], 'discount_sen')
            ->withMax(['orders as last_order_at' => fn ($q) => $q->where('status', 'paid')], 'paid_at')
            ->with(['vouchers' => fn ($q) => $q->withCount('redemptionOrders as used_count')->orderBy('code')]);
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        // Normalise before validating so the unique rule sees the stored (uppercase) code.
        $request->merge(['code' => Vendor::normaliseCode((string) $request->input('code', ''))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('vendors', 'code')->ignore($vendor)],
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $data['contact_name'] = $data['contact_name'] ?? null;
        $data['contact_phone'] = $data['contact_phone'] ?? null;
        $data['contact_email'] = $data['contact_email'] ?? null;
        $data['notes'] = $data['notes'] ?? null;

        return $data;
    }

    private function row(Vendor $v): array
    {
        return [
            'id' => $v->id,
            'code' => $v->code,
            'name' => $v->name,
            'contact_name' => $v->contact_name,
            'contact_phone' => $v->contact_phone,
            'contact_email' => $v->contact_email,
            'notes' => $v->notes,
            'is_active' => (bool) $v->is_active,
            'vouchers_count' => (int) ($v->vouchers_count ?? $v->vouchers->count()),
            'orders_count' => (int) ($v->orders_count ?? 0),
            'paid_orders_count' => (int) ($v->paid_orders_count ?? 0),
            'revenue_sen' => (int) ($v->revenue_sen ?? 0),
            'discount_sen' => (int) ($v->discount_sen ?? 0),
            'last_order_at' => $v->last_order_at,
            'created_at' => $v->created_at,
            'vouchers' => $v->vouchers->map(fn (Voucher $x) => [
                'id' => $x->id,
                'code' => $x->code,
                'type' => $x->type,
                'value' => (int) $x->value,
                'usage_limit' => $x->usage_limit,
                'used_count' => (int) ($x->used_count ?? 0),
                'is_active' => (bool) $x->is_active,
            ])->values(),
        ];
    }
}
