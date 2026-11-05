<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Template;
use App\Models\TemplateCategory;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => Template::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Template $t) => $t->toCatalog())
                ->values(),
            'categories' => TemplateCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('name'),
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
