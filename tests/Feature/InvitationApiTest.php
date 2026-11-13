<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_health_endpoint_returns_only_a_status_marker(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_catalog_lists_only_active_templates_and_packages(): void
    {
        $this->getJson('/api/v1/templates')->assertOk()
            ->assertJsonCount(22, 'data')
            ->assertJsonFragment(['slug' => 'mawar-sutra'])
            ->assertJsonFragment(['slug' => 'embun-pagi'])
            ->assertJsonFragment(['slug' => 'senja-asmara'])
            ->assertJsonFragment(['slug' => 'burgundy-rose'])
            ->assertJsonFragment(['slug' => 'wildflower-romance']);
        $this->getJson('/api/v1/packages')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.price_sen', 1900);
    }

    public function test_design_and_end_time_are_validated_and_stored(): void
    {
        $owner = User::factory()->create();
        $design = ['nameFont' => 'allura', 'primary' => '#aa2244', 'bodyTextColor' => '#174f74', 'accent' => '#cc8899', 'nameScale' => 120, 'nameWeight' => 500, 'textScale' => 110, 'letterSpacing' => 0.5, 'textAlign' => 'left', 'lineHeight' => 155, 'bodyFont' => 'playfair', 'textTone' => 'light', 'backgroundColor' => '#fff8ef', 'floralScale' => 110, 'floralOffsetX' => 4, 'floralOffsetY' => -2, 'floralRotation' => 6, 'floralOpacity' => 75, 'intro' => true, 'motion' => false];

        $id = $this->actingAs($owner)->postJson('/api/v1/me/invitations', [
            'template' => 'mawar-sutra',
            'content' => [
                'bride' => 'Alya',
                'groom' => 'Hakim',
                'date' => '2027-01-15',
                'time' => '11:00',
                'endTime' => '15:30',
                'venue' => 'WG Glassbox',
                'address' => 'Cyberjaya',
                'hashtag' => '#AlyaHakim2027',
                'couplePhoto' => 'data:image/jpeg;base64,AAAA',
                'musicUrl' => 'https://cdn.example.com/wedding.mp3',
                'schedule' => [['time' => '11:00', 'title' => 'Akad nikah', 'description' => 'Ketibaan keluarga', 'type' => 'akad-nikah', 'date' => '2027-01-15', 'endTime' => '12:00', 'venue' => 'Dewan Seri', 'address' => 'Cyberjaya', 'mapsUrl' => 'https://maps.google.com/?q=dewan', 'dressCode' => 'Baju Melayu', 'rsvpEnabled' => true]],
                'rsvpDeadline' => '2027-01-01',
                'design' => $design,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.content.endTime', '15:30')
            ->assertJsonPath('data.content.venue', 'WG Glassbox')
            ->assertJsonPath('data.content.hashtag', '#AlyaHakim2027')
            ->assertJsonPath('data.content.schedule.0.title', 'Akad nikah')
            ->assertJsonPath('data.content.schedule.0.mapsUrl', 'https://maps.google.com/?q=dewan')
            ->assertJsonPath('data.content.design.nameFont', 'allura')
            ->assertJsonPath('data.content.design.bodyFont', 'playfair')
            ->assertJsonPath('data.content.design.bodyTextColor', '#174f74')
            ->assertJsonPath('data.content.design.nameWeight', 500)
            ->assertJsonPath('data.content.design.letterSpacing', 0.5)
            ->assertJsonPath('data.content.rsvpDeadline', '2027-01-01')
            ->json('data.id');

        foreach ([['nameFont' => 'comic-sans'], ['primary' => 'red'], ['bodyTextColor' => 'red'], ['nameScale' => 400], ['nameWeight' => 800], ['textScale' => 200], ['letterSpacing' => 4], ['textAlign' => 'justify'], ['lineHeight' => 200], ['bodyFont' => 'comic-sans'], ['textTone' => 'neon'], ['backgroundColor' => 'ivory'], ['floralScale' => 200], ['background' => 'data:image/svg+xml;base64,AAAA']] as $bad) {
            $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['design' => $bad]])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['endTime' => '25:99']])->assertUnprocessable();
        $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['musicUrl' => 'http://example.com/song.mp3']])->assertUnprocessable();
        $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['schedule' => [['time' => '25:99', 'title' => 'Akad']]]])->assertUnprocessable();
        $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['schedule' => ['not-an-event']]])->assertUnprocessable();
    }

    public function test_invitation_management_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/invitations')->assertUnauthorized();
        $this->postJson('/api/v1/me/invitations')->assertUnauthorized();
    }

    public function test_unpublished_invitations_are_not_public(): void
    {
        $invitation = $this->createInvitation('draft');

        $this->getJson("/api/v1/invitations/public/{$invitation->slug}")->assertNotFound();
    }

    public function test_public_rsvp_is_idempotent_and_does_not_expose_guest_details(): void
    {
        $invitation = $this->createInvitation('published');
        $invitation->update(['rsvp_enabled' => true]);
        $payload = ['guest_name' => 'Guest Example', 'attendance' => 'attending', 'pax' => 2, 'submission_key' => (string) Str::uuid()];

        $first = $this->postJson("/api/v1/invitations/public/{$invitation->slug}/rsvp", $payload)
            ->assertCreated()->assertJsonMissingPath('data.guest_name');
        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/rsvp", $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('rsvp_responses', 1);
    }

    public function test_public_rsvp_accepts_only_enabled_schedule_events_and_enforces_deadline(): void
    {
        $invitation = $this->createInvitation('published');
        $invitation->update([
            'rsvp_enabled' => true,
            'content' => [
                'bride' => 'Alya',
                'groom' => 'Hakim',
                'schedule' => [
                    ['title' => 'Akad Nikah', 'rsvpEnabled' => true],
                    ['title' => 'Resepsi', 'rsvpEnabled' => false],
                    null,
                    'malformed',
                ],
            ],
            'rsvp_deadline' => now()->addDay(),
        ]);
        $payload = [
            'guest_name' => 'Tetamu',
            'attendance' => 'attending',
            'pax' => 2,
            'submission_key' => (string) Str::uuid(),
        ];

        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/rsvp", $payload + ['event_title' => 'Resepsi'])
            ->assertUnprocessable();
        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/rsvp", $payload + ['event_title' => 'Akad Nikah'])
            ->assertCreated();
        $this->assertDatabaseHas('rsvp_responses', ['invitation_id' => $invitation->id, 'event_title' => 'Akad Nikah']);

        $invitation->update(['rsvp_deadline' => now()->subMinute()]);
        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/rsvp", array_replace($payload, ['submission_key' => (string) Str::uuid()]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('rsvp_responses', 1);
    }

    public function test_owner_can_list_rsvps_but_others_cannot(): void
    {
        $invitation = $this->createInvitation('published');

        $this->actingAs($invitation->user)->getJson("/api/v1/me/invitations/{$invitation->id}/rsvps")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/me/invitations/{$invitation->id}/rsvps")->assertNotFound();
    }

    private function createInvitation(string $status): Invitation
    {
        return Invitation::create([
            'user_id' => User::factory()->create()->id,
            'template_id' => Template::where('slug', 'seri-kasih')->firstOrFail()->id,
            'slug' => 'alya-hakim-'.Str::lower(Str::random(5)),
            'status' => $status,
            'content' => ['bride' => 'Alya', 'groom' => 'Hakim'],
        ]);
    }
}
