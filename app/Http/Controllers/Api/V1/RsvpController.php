<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\RsvpResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RsvpController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:100'],
            'attendance' => ['required', 'in:attending,not_attending'],
            'pax' => ['required', 'integer', 'min:1', 'max:10'],
            'event_title' => ['nullable', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'submission_key' => ['required', 'uuid'],
            'company' => ['sometimes', 'prohibited'],
        ]);

        $invitation = Invitation::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('rsvp_enabled', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->firstOrFail();

        if ($invitation->rsvp_deadline?->isPast()) {
            return response()->json(['message' => 'The RSVP deadline has passed.'], 422);
        }

        $events = collect($invitation->content['schedule'] ?? [])
            ->filter(fn ($event) => is_array($event) && ($event['rsvpEnabled'] ?? true) && filled($event['title'] ?? null))
            ->pluck('title');
        if (filled($validated['event_title'] ?? null) && ! $events->contains($validated['event_title'])) {
            return response()->json(['message' => 'The selected event is not available for RSVP.'], 422);
        }

        try {
            $response = $invitation->rsvpResponses()->create($validated);
        } catch (UniqueConstraintViolationException) {
            $response = RsvpResponse::query()
                ->where('invitation_id', $invitation->id)
                ->where('submission_key', $validated['submission_key'])
                ->first();

            if ($response) {
                return response()->json(['message' => 'RSVP already received.', 'data' => ['id' => $response->id]]);
            }

            return response()->json(['message' => 'The RSVP could not be saved.'], 409);
        }

        return response()->json([
            'message' => 'RSVP received.',
            'data' => ['id' => $response->id],
        ], 201);
    }

    public function index(Request $request, Invitation $invitation): JsonResponse
    {
        abort_unless($invitation->user_id === $request->user()->id || $request->user()->is_admin, 404);

        $responses = $invitation->rsvpResponses()
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $responses,
            'summary' => [
                'responses' => $invitation->rsvpResponses()->count(),
                'attending' => $invitation->rsvpResponses()->where('attendance', 'attending')->sum('pax'),
                'not_attending' => $invitation->rsvpResponses()->where('attendance', 'not_attending')->sum('pax'),
                'by_event' => $invitation->rsvpResponses()
                    ->selectRaw('event_title, attendance, SUM(pax) as guests')
                    ->groupBy('event_title', 'attendance')
                    ->get(),
            ],
        ]);
    }

    public function export(Request $request, Invitation $invitation): StreamedResponse
    {
        abort_unless($invitation->user_id === $request->user()->id || $request->user()->is_admin, 404);

        return response()->streamDownload(function () use ($invitation): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, ['Guest name', 'Attendance', 'Pax', 'Event', 'Contact', 'Notes', 'Submitted at']);

            $invitation->rsvpResponses()->orderBy('id')->chunk(500, function ($responses) use ($output): void {
                foreach ($responses as $response) {
                    fputcsv($output, [
                        $response->guest_name,
                        $response->attendance,
                        $response->pax,
                        $response->event_title,
                        $response->contact,
                        $response->notes,
                        $response->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($output);
        }, "rsvp-{$invitation->slug}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
