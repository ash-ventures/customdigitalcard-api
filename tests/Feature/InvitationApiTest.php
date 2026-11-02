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
        $this->getJson('/api/v1/templates')->assertOk()->        assertJsonCount(17, 'data');
        $this->getJson('/api/v1/packages')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.price_sen', 1900);
    }

            public function test_design_and_end_time_are_validated_and_stored(): void
            {
                $owner = User::factory()->create();
                $design = ['nameFont' => 'allura', 'primary' => '#aa2244', 'accent' => '#cc8899', 'nameScale' => 120, 'textTone' => 'light', 'intro' => true, 'motion' => false];

                $id = $this->actingAs($owner)->postJson('/api/v1/me/invitations', ['template' => 'reka-sendiri', 'content' => ['bride' => 'Alya', 'time' => '11:00', 'endTime' => '15:30', 'design' => $design]])
                    ->assertCreated()->assertJsonPath('data.content.endTime', '15:30')->assertJsonPath('data.content.design.nameFont', 'allura')->json('data.id');

                foreach ([['nameFont' => 'comic-sans'], ['primary' => 'red'], ['nameScale' => 400], ['textTone' => 'neon'], ['background' => 'data:image/svg+xml;base64,AAAA']] as $bad) {
                    $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['design' => $bad]])->assertUnprocessable();
                }
                $this->actingAs($owner)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['endTime' => '25:99']])->assertUnprocessable();
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
