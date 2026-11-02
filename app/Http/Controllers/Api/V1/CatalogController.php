<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Template;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => Template::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function packages(): JsonResponse
    {
        return response()->json([
            'data' => Package::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }
}
