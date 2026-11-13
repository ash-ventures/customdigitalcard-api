<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CardDesignController extends Controller
{
    private const MAX_BACKGROUND_BYTES = 3_000_000;

    public function index(): JsonResponse
    {
        $cards = Template::query()
            ->withCount('invitations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Template $t) => $this->row($t));

        return response()->json([
            'data' => $cards,
            'categories' => $this->categoryRows(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $background = $this->decodeBackground($request->input('background'), required: true);

        $template = DB::transaction(function () use ($data, $background) {
            $template = Template::create([
                'code' => 'TMP'.Str::upper(Str::random(12)),
                'slug' => $this->uniqueSlug($data['name']),
                'name' => $data['name'],
                'category' => $data['category'],
                'theme' => 'custom',
                'description' => $data['description'] ?? '',
                'is_premium' => $data['is_premium'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'is_custom' => true,
                'sort_order' => ((int) Template::max('sort_order')) + 1,
                'configuration' => $this->configuration($data),
            ]);
            $template->update(['code' => Template::codeFor($template->id)]);
            $this->storeBackground($template, $background);

            return $template->fresh();
        });

        return response()->json(['data' => $this->row($template->loadCount('invitations'))], 201);
    }

    public function update(Request $request, Template $template): JsonResponse
    {
        $data = $this->validated($request);
        $background = $this->decodeBackground($request->input('background'), required: false);

        if ($background && ! $template->is_custom) {
            return response()->json(['message' => 'Kad bawaan tidak menggunakan imej latar.'], 422);
        }

        DB::transaction(function () use ($template, $data, $background) {
            $template->update([
                'name' => $data['name'],
                'category' => $data['category'],
                'description' => $data['description'] ?? '',
                'is_premium' => $data['is_premium'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'configuration' => [...array_diff_key($template->configuration ?? [], ['mood' => 1, 'text_tone' => 1, 'accent_color' => 1]), ...$this->configuration($data)],
            ]);
            if ($background) {
                $this->storeBackground($template, $background);
            }
        });

        return response()->json(['data' => $this->row($template->fresh()->loadCount('invitations'))]);
    }

    public function destroy(Template $template): JsonResponse
    {
        if (! $template->is_custom) {
            $template->update(['is_active' => false]);

            return response()->json(['data' => $this->row($template->fresh()->loadCount('invitations')), 'message' => 'Kad bawaan hanya boleh dinyahaktifkan.']);
        }
        if ($template->invitations()->exists()) {
            $template->update(['is_active' => false]);

            return response()->json(['data' => $this->row($template->fresh()->loadCount('invitations')), 'message' => 'Kad ini sudah digunakan, dinyahaktifkan sahaja.']);
        }
        if ($template->background_path) {
            Storage::disk('local')->delete($template->background_path);
        }
        $template->delete();

        return response()->json(['data' => null]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct', 'exists:templates,id'],
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $index => $id) {
                Template::whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return response()->json(['data' => true]);
    }

    public function background(string $code): BinaryFileResponse
    {
        $template = Template::where('code', $code)->whereNotNull('background_path')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($template->background_path), 404);

        return response()->file(Storage::disk('local')->path($template->background_path), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->categoryRows()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'unique:template_categories,name'],
        ]);
        $category = TemplateCategory::create([
            'name' => trim($data['name']),
            'sort_order' => ((int) TemplateCategory::max('sort_order')) + 1,
            'is_system' => false,
        ]);

        return response()->json(['data' => $this->categoryRow($category)], 201);
    }

    public function updateCategory(Request $request, TemplateCategory $category): JsonResponse
    {
        if ($category->is_system) {
            return response()->json(['message' => 'Kategori asas tidak boleh diubah nama.'], 422);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('template_categories', 'name')->ignore($category->id)],
        ]);
        DB::transaction(function () use ($category, $data) {
            Template::where('category', $category->name)->update(['category' => trim($data['name'])]);
            $category->update(['name' => trim($data['name'])]);
        });

        return response()->json(['data' => $this->categoryRow($category->fresh())]);
    }

    public function destroyCategory(TemplateCategory $category): JsonResponse
    {
        if (Template::where('category', $category->name)->exists()) {
            return response()->json(['message' => 'Pindahkan kad dalam kategori ini dahulu sebelum memadam.'], 422);
        }
        $category->delete();

        return response()->json(['data' => null]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'category' => ['required', 'string', Rule::exists('template_categories', 'name')],
            'description' => ['nullable', 'string', 'max:300'],
            'mood' => ['nullable', 'string', 'max:80'],
            'text_tone' => ['nullable', Rule::in(['dark', 'light'])],
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_premium' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    private function configuration(array $data): array
    {
        return array_filter([
            'mood' => $data['mood'] ?? null,
            'text_tone' => $data['text_tone'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
        ], fn ($value) => filled($value));
    }

    /** @return array{bytes: string, ext: string}|null */
    private function decodeBackground(mixed $value, bool $required): ?array
    {
        if (! filled($value)) {
            abort_if($required, 422, 'Imej latar diperlukan.');

            return null;
        }
        abort_unless(is_string($value) && preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/=]+)$/', $value, $m), 422, 'Imej latar mesti PNG, JPG atau WebP.');
        $bytes = base64_decode($m[2], true);
        abort_if($bytes === false || strlen($bytes) > self::MAX_BACKGROUND_BYTES, 422, 'Imej latar terlalu besar atau rosak.');
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true), 422, 'Fail imej tidak sah.');

        return ['bytes' => $bytes, 'ext' => $m[1] === 'jpeg' ? 'jpg' : $m[1]];
    }

    private function storeBackground(Template $template, array $background): void
    {
        $path = 'card-backgrounds/'.$template->code.'-'.Str::lower(Str::random(6)).'.'.$background['ext'];
        Storage::disk('local')->put($path, $background['bytes']);
        if ($template->background_path) {
            Storage::disk('local')->delete($template->background_path);
        }
        $template->update(['background_path' => $path]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'kad';
        $slug = $base;
        while (Template::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    private function row(Template $t): array
    {
        return [
            'id' => $t->id,
            ...$t->toCatalog(),
            'is_active' => $t->is_active,
            'invitations_count' => (int) ($t->invitations_count ?? 0),
        ];
    }

    private function categoryRows(): array
    {
        return TemplateCategory::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (TemplateCategory $c) => $this->categoryRow($c))->all();
    }

    private function categoryRow(TemplateCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'is_system' => $c->is_system,
            'cards_count' => Template::where('category', $c->name)->count(),
        ];
    }
}
