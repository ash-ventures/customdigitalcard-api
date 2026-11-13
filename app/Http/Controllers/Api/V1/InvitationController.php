<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function publicShow(Request $request, string $slug): JsonResponse
    {
        $invitation = Invitation::query()
            ->where('slug', $slug)
            ->with('template')
            ->firstOrFail();

        $supportPreview = $invitation->status === 'pending_payment'
            && InvitationSupport::hasValidSupportToken($invitation, $request->query('support'));
        $published = $invitation->status === 'published'
            && (! $invitation->expires_at || $invitation->expires_at->isFuture());

        abort_unless($published || $supportPreview, 404);

        return response()->json([
            'data' => [
                'slug' => $invitation->slug,
                'content' => $invitation->content,
                'rsvp_enabled' => ! $supportPreview && $invitation->rsvp_enabled,
                'guestbook_enabled' => ! $supportPreview && $invitation->guestbook_enabled,
                'rsvp_deadline' => $invitation->rsvp_deadline,
                'expires_at' => $invitation->expires_at,
                'support_preview' => $supportPreview,
                'template' => $invitation->template->toCatalog(),
            ],
        ]);
    }
}
