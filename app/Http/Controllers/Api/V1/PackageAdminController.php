<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Package::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price_sen' => ['required', 'integer', 'min:0', 'max:100000000'],
            'features' => ['required', 'array', 'min:1', 'max:20'],
            'features.*' => ['required', 'string', 'max:200'],
            'validity_days_after_event' => ['required', 'integer', 'between:0,65535'],
            'rsvp_enabled' => ['required', 'boolean'],
            'guestbook_enabled' => ['required', 'boolean'],
            'premium_templates' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
        ]);
        $package->update($validated);

        return response()->json(['data' => $package->refresh()]);
    }
}
