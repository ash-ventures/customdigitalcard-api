<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'package' => ['required', 'exists:packages,slug'],
            'email' => ['nullable', 'email'],
        ]);
        $package = Package::where('slug', $data['package'])->firstOrFail();
        $voucher = Voucher::where('code', Voucher::normalise($data['code']))->first();
        $email = $data['email'] ?? auth('sanctum')->user()?->email;

        try {
            if (! $voucher) {
                throw new \InvalidArgumentException('Kod baucar tidak sah.');
            }
            $result = $voucher->apply($package, $email);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['code' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => [
            'code' => $voucher->code,
            'description' => $voucher->description,
            'subtotal_sen' => (int) $package->price_sen,
            'discount_sen' => $result['discount_sen'],
            'total_sen' => $result['total_sen'],
        ]]);
    }

    public function index(): JsonResponse
    {
        $vouchers = Voucher::query()->latest()->get()->map(fn (Voucher $v) => $this->row($v));

        return response()->json(['data' => $vouchers]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['code'] = Voucher::normalise($data['code']);
        if (Voucher::where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'Kod baucar sudah wujud.', 'errors' => ['code' => ['Kod baucar sudah wujud.']]], 422);
        }

        return response()->json(['data' => $this->row(Voucher::create($data))], 201);
    }

    public function update(Request $request, Voucher $voucher): JsonResponse
    {
        $data = $this->validated($request, $voucher);
        $data['code'] = Voucher::normalise($data['code']);
        if ($data['code'] !== $voucher->code && Voucher::where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'Kod baucar sudah wujud.', 'errors' => ['code' => ['Kod baucar sudah wujud.']]], 422);
        }
        $voucher->update($data);

        return response()->json(['data' => $this->row($voucher->fresh())]);
    }

    public function destroy(Voucher $voucher): JsonResponse
    {
        if ($voucher->orders()->exists()) {
            $voucher->update(['is_active' => false]);

            return response()->json(['data' => $this->row($voucher->fresh()), 'message' => 'Baucar telah digunakan, dinyahaktifkan sahaja.']);
        }
        $voucher->delete();

        return response()->json(['data' => null]);
    }

    private function validated(Request $request, ?Voucher $voucher = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'description' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1', $request->input('type') === 'percent' ? 'max:100' : 'max:100000'],
            'min_amount_sen' => ['nullable', 'integer', 'min:0'],
            'max_discount_sen' => ['nullable', 'integer', 'min:1'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'package_slug' => ['nullable', 'exists:packages,slug'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
        ]);
        $data['min_amount_sen'] = $data['min_amount_sen'] ?? 0;

        return $data;
    }

    private function row(Voucher $v): array
    {
        return [
            ...$v->only(['id', 'code', 'description', 'type', 'value', 'min_amount_sen', 'max_discount_sen',
                'usage_limit', 'per_customer_limit', 'package_slug', 'is_active']),
            'starts_at' => $v->starts_at?->toIso8601String(),
            'expires_at' => $v->expires_at?->toIso8601String(),
            'used_count' => $v->redemptions()->count(),
            'discount_total_sen' => (int) $v->orders()->where('status', 'paid')->sum('discount_sen'),
        ];
    }
}
