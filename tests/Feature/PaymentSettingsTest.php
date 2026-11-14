<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function checkoutPayload(): array
    {
        return [
            'template' => 'seri-kasih',
            'package' => 'starter',
            'content' => [
                'bride' => 'Alya',
                'groom' => 'Hakim',
                'date' => now()->addMonth()->toDateString(),
                'time' => '11:00',
            ],
            'customer' => [
                'name' => 'Aminah Ali',
                'phone' => '012-345 6789',
                'email' => 'aminah@example.test',
            ],
        ];
    }

    public function test_manual_order_stays_private_until_admin_confirms_payment(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/payment-settings', [
                'provider' => 'manual',
                'bank_name' => 'Maybank',
                'bank_type' => 'savings',
                'account_holder' => 'Majlis Digital',
                'account_number' => '1234567890',
                'whatsapp' => '+60123456789',
                'email' => 'payments@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'manual')
            ->assertJsonPath('data.manual.whatsapp', '60123456789');

        $checkout = $this->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.payment_url', null)
            ->assertJsonPath('data.status', 'pending');
        $reference = $checkout->json('data.order_reference');
        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $invitation = Invitation::query()->findOrFail($order->invitation_id);

        $this->assertSame('manual', $order->provider);
        $this->assertSame('Maybank', $order->payment_instructions['bank_name']);
        $this->assertSame('pending_payment', $invitation->status);

        $this->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_instructions.bank_name', 'Maybank')
            ->assertJsonPath('data.payment_instructions.account_number', '1234567890')
            ->assertJsonPath('data.invitation.public_path', null);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-manual-payment")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'manual_confirmed_by' => User::query()->where('is_admin', true)->latest('id')->value('id'),
        ]);
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => 'published']);
        $this->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.payment_instructions', null)
            ->assertJsonPath('data.invitation.public_path', "/jemputan/{$invitation->slug}");
    }

    public function test_manual_settings_require_a_payment_destination_and_receipt_contact(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/payment-settings', ['provider' => 'manual'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qr', 'whatsapp']);
    }

    public function test_admin_can_upload_and_serve_payment_qr_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $result = $this->actingAs($admin)
            ->post('/api/v1/admin/payment-settings', [
                'provider' => 'manual',
                'whatsapp' => '+60123456789',
                'qr' => UploadedFile::fake()->image('business-qr.png'),
            ])
            ->assertOk();

        $qrUrl = $result->json('data.manual.qr_url');
        $this->assertIsString($qrUrl);
        $response = $this->get($qrUrl)->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));

        $this->actingAs($admin)
            ->post('/api/v1/admin/payment-settings', [
                'provider' => 'manual',
                'whatsapp' => '+60123456789',
                'qr' => UploadedFile::fake()->image('replacement-qr.png'),
            ])
            ->assertOk();
        $this->get($qrUrl)->assertOk();
    }

    public function test_non_manual_order_cannot_be_confirmed_through_manual_action(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/payment-settings', ['provider' => 'chip'])
            ->assertOk();
        config([
            'services.chip.api_key' => 'test-key',
            'services.chip.brand_id' => 'brand-test-id',
            'services.chip.base_url' => 'https://gate.chip-in.asia/api/v1',
            'services.chip.frontend_url' => 'http://127.0.0.1:3000',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => \Illuminate\Support\Facades\Http::response([
                'id' => 'purchase-non-manual',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-non-manual/',
            ], 201),
        ]);

        $orderReference = $this->postJson('/api/v1/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->json('data.order_reference');
        $order = Order::query()->where('reference', $orderReference)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-manual-payment")
            ->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
    }
}
