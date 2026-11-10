<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;

class InvitationController extends Controller
{
    public function publicShow(string $slug): JsonResponse
    {
        $invitation = Invitation::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('template')
            ->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $invitation->slug,
                'content' => $invitation->content,
                'rsvp_enabled' => $invitation->rsvp_enabled,
                'guestbook_enabled' => $invitation->guestbook_enabled,
                'rsvp_deadline' => $invitation->rsvp_deadline,
                'expires_at' => $invitation->expires_at,
                'template' => $invitation->template->toCatalog(),
            ],
        ]);
    }
}
