<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishController extends Controller
{
    public function index(string $slug): JsonResponse
    {
        $invitation = $this->published($slug);
        $this->ensureGuestbookEnabled($invitation);

        return response()->json(['data' => $invitation->wishes()->latest('id')->limit(100)->get(['name', 'message', 'created_at'])]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $invitation = $this->published($slug);
        $this->ensureGuestbookEnabled($invitation);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'message' => ['required', 'string', 'max:240'],
            'company' => ['sometimes', 'prohibited'],
        ]);

        $wish = $invitation->wishes()->create([
            'name' => trim($validated['name']),
            'message' => trim($validated['message']),
        ]);

        return response()->json(['data' => $wish->only(['name', 'message', 'created_at'])], 201);
    }

    private function ensureGuestbookEnabled(Invitation $invitation): void
    {
        abort_unless(
            InvitationSupport::entitlements($invitation)['guestbook'] && ($invitation->content['showWishes'] ?? true),
            404,
        );
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
}
