<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PaymentSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = PaymentSetting::current();

        return response()->json(['data' => [
            'provider' => $settings?->provider ?? config('services.payments.provider', 'chip'),
            'manual' => $settings?->manualInstructions() ?? [
                'qr_url' => null,
                'bank_name' => null,
                'bank_type' => null,
                'account_holder' => null,
                'account_number' => null,
                'whatsapp' => null,
                'email' => null,
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['chip', 'billplz', 'manual'])],
            'qr' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_qr' => ['sometimes', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_type' => ['nullable', Rule::in(['savings', 'current'])],
            'account_holder' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]+$/'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s()-]{8,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $current = PaymentSetting::current();
        $removeQr = (bool) ($validated['remove_qr'] ?? false);
        $hasQr = isset($validated['qr']) || ($current?->manual_qr_path && ! $removeQr);
        $hasBank = filled($validated['bank_name'] ?? null)
            && filled($validated['account_holder'] ?? null)
            && filled($validated['account_number'] ?? null);
        $hasContact = filled($validated['whatsapp'] ?? null) || filled($validated['email'] ?? null);

        if ($validated['provider'] === 'manual') {
            $errors = [];
            if (! $hasQr && ! $hasBank) {
                $errors['qr'] = ['Add a payment QR code or complete bank account details.'];
            }
            if (! $hasContact) {
                $errors['whatsapp'] = ['Add a WhatsApp number or email so customers can send their payment receipt.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        $newQrPath = isset($validated['qr'])
            ? $validated['qr']->storePublicly('payment-qr', 'public')
            : null;
        if (isset($validated['qr']) && ! $newQrPath) {
            throw new \RuntimeException('Unable to store the payment QR image.');
        }
        $oldQrPath = $current?->manual_qr_path;
        $qrPath = $newQrPath ?? ($removeQr ? null : $oldQrPath);
        $whatsapp = preg_replace('/\D+/', '', (string) ($validated['whatsapp'] ?? '')) ?: null;

        try {
            $settings = DB::transaction(fn () => PaymentSetting::query()->updateOrCreate(
                ['id' => 1],
                [
                    'provider' => $validated['provider'],
                    'manual_qr_path' => $qrPath,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'bank_type' => $validated['bank_type'] ?? null,
                    'account_holder' => $validated['account_holder'] ?? null,
                    'account_number' => $validated['account_number'] ?? null,
                    'whatsapp' => $whatsapp,
                    'email' => $validated['email'] ?? null,
                    'updated_by' => $request->user()?->id,
                ],
            ));
        } catch (\Throwable $exception) {
            if ($newQrPath) {
                Storage::disk('public')->delete($newQrPath);
            }
            throw $exception;
        }

        // Keep replaced QR files because pending order snapshots can still reference them.
        return response()->json(['data' => [
            'provider' => $settings->provider,
            'manual' => $settings->manualInstructions(),
        ]]);
    }

    public function qr(string $filename): Response
    {
        abort_unless(preg_match('/\A[A-Za-z0-9._-]+\z/', $filename) === 1, 404);
        $path = 'payment-qr/'.$filename;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
