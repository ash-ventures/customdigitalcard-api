<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\PhotobookPhoto;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotobookController extends Controller
{
    public function index(string $slug, Request $request): JsonResponse
    {
        $invitation = $this->published($slug);
        abort_unless(InvitationSupport::entitlements($invitation)['photobook'] && ($invitation->content['photobookEnabled'] ?? true), 404);

        $page = $invitation->photobookPhotos()
            ->when($request->query('sort') === 'picks', fn ($q) => $q->orderByDesc('is_featured')->orderByDesc('likes_count')->latest('id'))
            ->when($request->query('sort') !== 'picks', fn ($q) => $q->latest('id'))
            ->paginate(100);

        return response()->json([
            'data' => $page->getCollection()->map(fn (PhotobookPhoto $photo) => $this->present($photo, $slug)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $invitation = $this->published($slug);
        abort_unless(InvitationSupport::entitlements($invitation)['photobook'] && ($invitation->content['photobookEnabled'] ?? true), 404);

        $validated = $request->validate([
            'sender_name' => ['required', 'string', 'max:60'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:160'],
            'image' => ['required', 'string', 'max:7000000', 'regex:/^data:image\/(jpeg|png|webp);base64,/'],
        ]);

        if (! preg_match('/^data:image\/(?:jpeg|png|webp);base64,(.+)$/s', $validated['image'], $matches)) {
            abort(422, 'Format gambar tidak disokong.');
        }
        $binary = base64_decode($matches[1], true);
        if ($binary === false) {
            abort(422, 'Gambar tidak sah.');
        }
        $info = getimagesizefromstring($binary);
        if ($info === false || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            abort(422, 'Gambar tidak sah.');
        }
        if (strlen($binary) > 5 * 1024 * 1024 || ($info[0] ?? 0) > 6000 || ($info[1] ?? 0) > 6000) {
            abort(422, 'Imej terlalu besar.');
        }

        $path = 'photobook/'.$invitation->id.'/'.Str::uuid()->toString().'.jpg';
        if (! Storage::disk('public')->put($path, $binary)) {
            abort(500, 'Gambar tidak dapat disimpan.');
        }

        $photo = $invitation->photobookPhotos()->create([
            'sender_name' => trim($validated['sender_name']),
            'relationship' => isset($validated['relationship']) ? trim($validated['relationship']) : null,
            'note' => isset($validated['note']) ? trim($validated['note']) : null,
            'image_path' => $path,
            'mime' => 'image/jpeg',
            'width' => min((int) ($info[0] ?? 720), 6000),
            'height' => min((int) ($info[1] ?? 900), 6000),
        ]);

        return response()->json(['data' => $this->present($photo, $slug)], 201);
    }

    public function image(string $slug, PhotobookPhoto $photo): StreamedResponse
    {
        $invitation = $this->published($slug);
        abort_unless($photo->invitation_id === $invitation->id, 404);
        abort_unless(Storage::disk('public')->exists($photo->image_path), 404);

        return Storage::disk('public')->response(
            $photo->image_path,
            null,
            ['Cache-Control' => 'public, max-age=60']
        );
    }

    public function toggleLike(Request $request, string $slug, PhotobookPhoto $photo): JsonResponse
    {
        $invitation = $this->published($slug);
        abort_unless(InvitationSupport::entitlements($invitation)['photobook'] && ($invitation->content['photobookEnabled'] ?? true), 404);
        abort_unless($photo->invitation_id === $invitation->id, 404);

        $validated = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/']]);

        $fingerprint = strtolower($validated['fingerprint']);
        $like = $photo->likes()->where('fingerprint', $fingerprint)->first();
        if ($like) {
            $like->delete();
            $photo->decrement('likes_count');
            $liked = false;
        } else {
            $photo->likes()->create(['fingerprint' => $fingerprint]);
            $photo->increment('likes_count');
            $liked = true;
        }
        $photo->refresh();

        return response()->json(['data' => ['liked' => $liked, 'likes_count' => $photo->likes_count]]);
    }

    private function published(string $slug): Invitation
    {
        return Invitation::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with('package')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();
    }

    private function present(PhotobookPhoto $photo, string $slug): array
    {
        return [
            'id' => $photo->id,
            'sender_name' => $photo->sender_name,
            'relationship' => $photo->relationship,
            'note' => $photo->note,
            'image_url' => '/api/v1/invitations/public/'.rawurlencode($slug).'/photobook/photos/'.$photo->id.'/image',
            'likes_count' => $photo->likes_count,
            'is_featured' => $photo->is_featured,
            'created_at' => $photo->created_at,
        ];
    }
}
