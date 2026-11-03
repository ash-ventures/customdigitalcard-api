<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Template;
use Illuminate\Support\Facades\DB;
use App\Support\InvitationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeInvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invitations = $request->user()->invitations()
            ->with(['template', 'package:id,slug,name', 'orders' => fn ($q) => $q->latest('id')])
            ->latest('updated_at')
            ->get();

        return response()->json(['data' => $invitations->map(fn (Invitation $i) => $this->present($i))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['template' => ['required', 'exists:templates,slug']] + InvitationSupport::contentRules());

        $invitation = $request->user()->invitations()->create([
            'template_id' => Template::where('slug', $validated['template'])->where('is_active', true)->firstOrFail()->id,
            'slug' => InvitationSupport::draftSlug(),
            'status' => 'draft',
            'content' => $validated['content'],
            'rsvp_deadline' => InvitationSupport::rsvpDeadline($validated['content']['rsvpDeadline'] ?? null),
        ]);

        return response()->json(['data' => $this->present($invitation->load('template', 'orders'))], 201);
    }

    public function update(Request $request, Invitation $invitation): JsonResponse
    {
        $this->ensureOwner($request, $invitation);
        $validated = $request->validate(['template' => ['sometimes', 'exists:templates,slug']] + InvitationSupport::contentRules());

        $attributes = [
            'content' => $validated['content'],
            'rsvp_deadline' => InvitationSupport::rsvpDeadline($validated['content']['rsvpDeadline'] ?? null),
        ];
        if (isset($validated['template']) && $invitation->status !== 'published') {
            $attributes['template_id'] = Template::where('slug', $validated['template'])->where('is_active', true)->firstOrFail()->id;
        }
        $invitation->update($attributes);

        return response()->json(['data' => $this->present($invitation->fresh()->load('template', 'package', 'orders'))]);
    }

    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->ensureOwner($request, $invitation);

        if ($invitation->status === 'published') {
            return response()->json(['message' => 'Kad yang telah diterbitkan tidak boleh dipadam.'], 422);
        }

        $invitation->delete();

        return response()->json(['message' => 'Dipadam.']);
    }

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate(['reference' => ['required', 'string', 'regex:/^JK-[A-Z0-9]+$/']]);
        $user = $request->user();

        $order = Order::query()->where('reference', $validated['reference'])->with(['customer', 'invitation'])->first();
        // The reference proves the purchase; the email match proves it is the same person.
        if (! $order || $order->user_id !== null || ! $order->customer || strtolower($order->customer->email) !== strtolower($user->email)) {
            return response()->json(['message' => 'Rujukan tidak ditemui atau emel tidak sepadan dengan akaun anda.'], 422);
        }

        DB::transaction(function () use ($order, $user) {
            $order->update(['user_id' => $user->id]);
            $order->invitation?->update(['user_id' => $user->id]);
            if (! $order->customer->user_id) {
                $order->customer->update(['user_id' => $user->id]);
            }
        });

        return response()->json(['message' => 'Kad dipindahkan ke akaun anda.']);
    }

    private function ensureOwner(Request $request, Invitation $invitation): void
    {
        abort_unless($invitation->user_id === $request->user()->id, 404);
    }

    private function present(Invitation $invitation): array
    {
        $order = $invitation->orders->firstWhere('status', 'paid') ?? $invitation->orders->first();

        return [
            'id' => $invitation->id,
            'status' => $invitation->status,
            'template' => InvitationSupport::template($invitation),
            'content' => $invitation->content,
            'rsvp_deadline' => $invitation->rsvp_deadline,
            'slug' => $invitation->slug,
            'public_path' => InvitationSupport::publicPath($invitation),
            'package' => $invitation->package?->only(['slug', 'name']),
            'order' => InvitationSupport::order($order),
            'expires_at' => $invitation->expires_at,
            'updated_at' => $invitation->updated_at,
        ];
    }
}
