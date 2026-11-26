<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Package;
use App\Models\PhotobookLike;
use App\Models\PhotobookPhoto;
use App\Models\RsvpResponse;
use App\Models\Template;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_PATH = 'photobook/1/guest-photo.jpg';

    private Customer $customer;

    private Invitation $invitation;

    private Order $order;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Storage::fake('public');

        $template = Template::query()->where('slug', 'seri-kasih')->firstOrFail();
        $package = Package::query()->where('slug', 'premium-plus')->firstOrFail();

        $this->customer = Customer::create([
            'full_name' => 'Aminah Ali',
            'email' => 'aminah@example.test',
            'phone' => '0123456789',
            'marketing_consent' => true,
            'source' => 'checkout',
        ]);

        $this->invitation = Invitation::create([
            'customer_id' => $this->customer->id,
            'template_id' => $template->id,
            'package_id' => $package->id,
            'slug' => 'alya-hakim-cleanup',
            'status' => 'published',
            'content' => ['bride' => 'Alya', 'groom' => 'Hakim', 'couplePhoto' => 'data:image/jpeg;base64,AAAA'],
            'rsvp_enabled' => true,
            'guestbook_enabled' => true,
            'photobook_enabled' => true,
            'published_at' => now(),
        ]);

        $this->order = $this->paidOrder($this->invitation);

        RsvpResponse::create([
            'invitation_id' => $this->invitation->id,
            'guest_name' => 'Tetamu Satu',
            'attendance' => 'attending',
            'pax' => 2,
            'submission_key' => 'key-1',
        ]);
        Wish::create([
            'invitation_id' => $this->invitation->id,
            'name' => 'Tetamu Dua',
            'message' => 'Selamat pengantin baru',
        ]);

        Storage::disk('public')->put(self::IMAGE_PATH, 'image-bytes');
        $photo = PhotobookPhoto::create([
            'invitation_id' => $this->invitation->id,
            'sender_name' => 'Tetamu Tiga',
            'image_path' => self::IMAGE_PATH,
        ]);
        PhotobookLike::create(['photobook_photo_id' => $photo->id, 'fingerprint' => str_repeat('a', 64)]);

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function paidOrder(Invitation $invitation, string $reference = 'JK-CLEANUP0001', string $status = 'paid'): Order
    {
        return Order::create([
            'customer_id' => $invitation->customer_id,
            'invitation_id' => $invitation->id,
            'package_id' => $invitation->package_id,
            'reference' => $reference,
            'amount_sen' => 6900,
            'status' => $status,
            'provider' => 'manual',
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    private function assertCardDataIsGone(): void
    {
        $this->assertDatabaseMissing('invitations', ['id' => $this->invitation->id]);
        $this->assertDatabaseMissing('orders', ['invitation_id' => $this->invitation->id]);
        $this->assertDatabaseMissing('rsvp_responses', ['invitation_id' => $this->invitation->id]);
        $this->assertDatabaseMissing('wishes', ['invitation_id' => $this->invitation->id]);
        $this->assertDatabaseMissing('photobook_photos', ['invitation_id' => $this->invitation->id]);
        $this->assertDatabaseMissing('photobook_likes', ['fingerprint' => str_repeat('a', 64)]);
        Storage::disk('public')->assertMissing(self::IMAGE_PATH);
    }

    public function test_deleting_the_last_order_removes_the_card_guest_data_images_and_revenue(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('orders', ['id' => $this->order->id]);
        $this->assertCardDataIsGone();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.orders_paid', 0)
            ->assertJsonPath('data.invitations_published', 0)
            ->assertJsonPath('data.revenue_sen', 0)
            ->assertJsonPath('data.revenue_30d_sen', 0);
    }

    public function test_deleting_an_order_keeps_the_card_while_another_order_exists(): void
    {
        $retry = $this->paidOrder($this->invitation, 'JK-CLEANUP0002', 'pending');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('orders', ['id' => $this->order->id]);
        $this->assertDatabaseHas('invitations', ['id' => $this->invitation->id]);
        $this->assertDatabaseHas('orders', ['id' => $retry->id]);
        $this->assertDatabaseHas('wishes', ['invitation_id' => $this->invitation->id]);
        Storage::disk('public')->assertExists(self::IMAGE_PATH);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertJsonPath('data.revenue_sen', 0)
            ->assertJsonPath('data.invitations_published', 1);
    }

    public function test_admin_can_delete_a_card_with_its_orders_and_guest_data(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/invitations/{$this->invitation->id}")
            ->assertOk();

        $this->assertCardDataIsGone();
        $this->assertDatabaseHas('customers', ['id' => $this->customer->id]);
    }

    public function test_admin_can_delete_a_registered_customer_with_the_login_account(): void
    {
        $user = User::factory()->create(['email' => 'aminah@example.test']);
        $this->customer->update(['user_id' => $user->id]);
        $this->invitation->update(['user_id' => $user->id]);
        $this->order->update(['user_id' => $user->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/customers/{$this->customer->id}?delete_account=1")
            ->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $this->customer->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertCardDataIsGone();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertJsonPath('data.customers_total', 0)
            ->assertJsonPath('data.revenue_sen', 0);
    }

    public function test_deleting_a_customer_keeps_the_login_account_by_default(): void
    {
        $user = User::factory()->create(['email' => 'aminah@example.test']);
        $this->customer->update(['user_id' => $user->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/customers/{$this->customer->id}")
            ->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $this->customer->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertCardDataIsGone();
    }

    public function test_cleanup_endpoints_are_admin_only(): void
    {
        $customer = User::factory()->create();

        $this->deleteJson("/api/v1/admin/orders/{$this->order->id}")->assertUnauthorized();
        $this->actingAs($customer)
            ->deleteJson("/api/v1/admin/invitations/{$this->invitation->id}")
            ->assertForbidden();
        $this->actingAs($customer)
            ->deleteJson("/api/v1/admin/customers/{$this->customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $this->order->id]);
        $this->assertDatabaseHas('invitations', ['id' => $this->invitation->id]);
        $this->assertDatabaseHas('customers', ['id' => $this->customer->id]);
    }
}
