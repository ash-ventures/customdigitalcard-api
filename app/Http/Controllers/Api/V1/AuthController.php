<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s-]{8,16}$/'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
            'marketing_consent' => (bool) ($validated['marketing_consent'] ?? false),
        ]);

        Customer::capture($user->name, $user->email, $user->phone, $user->marketing_consent, 'register', $user);

        return response()->json([
            'user' => $user->presented(),
            'token' => $user->createToken('customer-api')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        return $this->authenticate($request, false);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        return $this->authenticate($request, true);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->presented()]);
    }

    private function authenticate(Request $request, bool $adminOnly): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($credentials['email']))->first();

        if (! $user || blank($user->password) || ! Hash::check($credentials['password'], $user->password) || ($adminOnly && ! $user->is_admin)) {
            throw ValidationException::withMessages([
                'email' => ['Emel atau kata laluan tidak betul.'],
            ]);
        }

        return response()->json([
            'user' => $user->presented(),
            'token' => $user->createToken($adminOnly ? 'admin-api' : 'customer-api')->plainTextToken,
        ]);
    }
}
