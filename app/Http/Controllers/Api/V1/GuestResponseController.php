<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Shuchkin\SimpleXLSXGen;

/**
 * Owner-only view of everything guests sent to one published card: RSVP answers and prayers/wishes.
 * DEBUG: a 404 here means the invitation does not belong to the logged-in user (ownership check in ensureOwner()).
 */
class GuestResponseController extends Controller
{
    /** Upper bound for the dashboard list; the Excel export below is not capped. */
    private const LIST_LIMIT = 500;

    /** GET /me/invitations/{invitation}/responses */
    public function index(Request $request, Invitation $invitation): JsonResponse
    {
        $this->ensureOwner($request, $invitation);

        return $this->responses($invitation);
    }

    public function adminIndex(Invitation $invitation): JsonResponse
    {
        return $this->responses($invitation);
    }

    private function responses(Invitation $invitation): JsonResponse
    {
        $rsvps = $invitation->rsvpResponses()->latest('id');
        $wishes = $invitation->wishes()->latest('id');

        return response()->json(['data' => [
            'summary' => [
                'rsvp_total' => $invitation->rsvpResponses()->count(),
                'attending_guests' => (int) $invitation->rsvpResponses()->where('attendance', 'attending')->sum('pax'),
                'attending_replies' => $invitation->rsvpResponses()->where('attendance', 'attending')->count(),
                'not_attending_replies' => $invitation->rsvpResponses()->where('attendance', 'not_attending')->count(),
                'not_attending_guests' => (int) $invitation->rsvpResponses()->where('attendance', 'not_attending')->sum('pax'),
                'wishes_total' => $invitation->wishes()->count(),
            ],
            'rsvps' => $rsvps->limit(self::LIST_LIMIT)->get(['id', 'guest_name', 'attendance', 'pax', 'event_title', 'contact', 'notes', 'created_at']),
            'wishes' => $wishes->limit(self::LIST_LIMIT)->get(['id', 'name', 'message', 'created_at']),
        ]]);
    }

    /** GET /me/invitations/{invitation}/responses/export - real .xlsx with one sheet for RSVP and one for wishes. */
    public function export(Request $request, Invitation $invitation): Response
    {
        $this->ensureOwner($request, $invitation);

        // SimpleXLSXGen::raw() stores guest text as plain strings, so a guest typing "=SUM(...)" can never run as a formula.
        $text = fn (?string $value): string => SimpleXLSXGen::raw((string) $value);
        $when = fn ($date): string => $date?->timezone('Asia/Kuala_Lumpur')->format('d/m/Y H:i') ?? '';

        $rsvpRows = [['<b>Nama tetamu</b>', '<b>Kehadiran</b>', '<b>Bilangan tetamu</b>', '<b>Acara</b>', '<b>Telefon / emel</b>', '<b>Catatan</b>', '<b>Dihantar pada</b>']];
        $invitation->rsvpResponses()->orderBy('id')->each(function ($row) use (&$rsvpRows, $text, $when): void {
            $rsvpRows[] = [
                $text($row->guest_name),
                $row->attendance === 'attending' ? 'Hadir' : 'Tidak hadir',
                (int) $row->pax,
                $text($row->event_title),
                $text($row->contact),
                $text($row->notes),
                $when($row->created_at),
            ];
        });

        $wishRows = [['<b>Nama</b>', '<b>Doa & ucapan</b>', '<b>Dihantar pada</b>']];
        $invitation->wishes()->orderBy('id')->each(function ($row) use (&$wishRows, $text, $when): void {
            $wishRows[] = [$text($row->name), $text($row->message), $when($row->created_at)];
        });

        // setColWidth() always applies to the sheet added last, so widths are set right after each sheet is added.
        $xlsx = SimpleXLSXGen::fromArray($rsvpRows, 'RSVP')
            ->setColWidth(1, 28)->setColWidth(2, 16)->setColWidth(3, 18)->setColWidth(4, 32)->setColWidth(5, 26)->setColWidth(6, 40)->setColWidth(7, 20)
            ->addSheet($wishRows, 'Doa & Ucapan')
            ->setColWidth(1, 28)->setColWidth(2, 70)->setColWidth(3, 20);

        $filename = 'jawapan-'.($invitation->slug ?: $invitation->id).'-'.now()->format('Ymd').'.xlsx';

        return response((string) $xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function ensureOwner(Request $request, Invitation $invitation): void
    {
        abort_unless($invitation->user_id === $request->user()->id, 404);
        abort_unless(in_array($invitation->status, ['published', 'suspended'], true), 404);
    }
}
