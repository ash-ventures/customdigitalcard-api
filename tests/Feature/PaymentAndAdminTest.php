<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAndAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config([
            'services.billplz.api_key' => 'test-key',
            'services.billplz.collection_id' => 'col123',
            'services.billplz.x_signature_key' => 'sig-secret',
            'services.billplz.base_url' => 'https://www.billplz-sandbox.com/api/v3',
            'services.billplz.frontend_url' => 'http://127.0.0.1:3000',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'template' => 'seri-kasih',
            'package' => 'starter',
            'content' => ['bride' => 'Alya', 'groom' => 'Hakim', 'date' => now()->addMonth()->toDateString(), 'time' => '11:00', 'rsvpEnabled' => true],
            'customer' => ['name' => 'Aminah Ali', 'phone' => '012-345 6789', 'email' => 'Aminah@Example.test', 'marketing_consent' => true],
        ], $overrides);
    }

    private bool $billPaid = false;

    private int $billAmount = 1900;

    private int $billCounter = 0;

    private bool $faked = false;

    private function fakeBill(bool $paid = false, int $amount = 1900): void
    {
        $this->billPaid = $paid;
        $this->billAmount = $amount;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'www.billplz-sandbox.com/api/v3/bills' => function () {
                $id = 'bill_'.(++$this->billCounter);

                return Http::response(['id' => $id, 'url' => "https://www.billplz-sandbox.com/bills/{$id}", 'paid' => false], 200);
            },
            'www.billplz-sandbox.com/api/v3/bills/*' => function ($request) {
                $id = basename($request->url());

                return Http::response(['id' => $id, 'paid' => $this->billPaid, 'amount' => $this->billAmount, 'paid_amount' => $this->billPaid ? $this->billAmount : 0], 200);
            },
        ]);
    }

    private function checkout(array $overrides = []): string
    {
        $this->fakeBill();

        return $this->postJson('/api/v1/checkout', $this->payload($overrides))
            ->assertCreated()
            ->assertJsonPath('data.payment_url', 'https://www.billplz-sandbox.com/bills/bill_'.$this->billCounter)
            ->json('data.order_reference');
    }

    public function test_guest_checkout_creates_customer_order_and_bill(): void
    {
        $reference = $this->checkout();

        Http::assertSent(function ($request) use ($reference) {
            return str_ends_with($request->url(), '/bills')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:'))
                && $request['collection_id'] === 'col123'
                && (int) $request['amount'] === 1900
                && str_contains($request['redirect_url'], '/bayaran/status?ref='.$reference);
        });

        $this->assertDatabaseHas('customers', ['email' => 'aminah@example.test', 'marketing_consent' => true, 'source' => 'checkout']);
        $this->assertDatabaseHas('invitations', ['status' => 'pending_payment', 'user_id' => null]);
        $this->assertDatabaseHas('orders', ['reference' => $reference, 'status' => 'pending', 'amount_sen' => 1900, 'provider_reference' => 'bill_1']);
    }

    public function test_checkout_fails_cleanly_when_gateway_is_not_configured(): void
    {
        config(['services.billplz.api_key' => null]);

        $this->postJson('/api/v1/checkout', $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_premium_template_requires_premium_package(): void
    {
        $this->fakeBill();

        $this->postJson('/api/v1/checkout', $this->payload(['template' => 'taman-senja']))->assertUnprocessable();
    }

    public function test_gateway_error_marks_order_failed(): void
    {
        Http::fake(['*' => Http::response(['error' => 'x'], 500)]);

        $this->postJson('/api/v1/checkout', $this->payload())->assertStatus(502);
        $this->assertDatabaseHas('orders', ['status' => 'failed']);
    }

    public function test_paid_bill_publishes_invitation_and_exposes_unique_link(): void
    {
        $reference = $this->checkout();
        $slug = Invitation::firstOrFail()->slug;

        $this->getJson("/api/v1/invitations/public/{$slug}")->assertNotFound();

        $this->fakeBill(paid: true);
        $this->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invitation.public_path', "/jemputan/{$slug}");

        $this->getJson("/api/v1/invitations/public/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.content.bride', 'Alya')
            ->assertJsonMissingPath('data.user_id');

        $this->getJson("/api/v1/orders/{$reference}")->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Order::where('status', 'paid')->count());
    }

    public function test_amount_mismatch_does_not_publish(): void
    {
        $reference = $this->checkout();
        $this->fakeBill(paid: true, amount: 100);

        $this->getJson("/api/v1/orders/{$reference}")
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invitation.public_path', null);
    }

    public function test_callback_requires_valid_signature_and_publishes(): void
    {
        $reference = $this->checkout();
        $params = ['id' => 'bill_1', 'collection_id' => 'col123', 'paid' => 'true', 'state' => 'paid', 'amount' => '1900', 'paid_amount' => '1900'];
        $pairs = collect($params)->map(fn ($v, $k) => $k.$v)->sort(SORT_STRING | SORT_FLAG_CASE)->values()->all();
        $signature = hash_hmac('sha256', implode('|', $pairs), 'sig-secret');

        $this->post('/api/v1/payments/billplz/callback', $params + ['x_signature' => 'bad'])->assertForbidden();
        $this->assertSame('pending', Order::where('reference', $reference)->value('status'));

        $this->post('/api/v1/payments/billplz/callback', $params + ['x_signature' => $signature])->assertOk();
        $this->post('/api/v1/payments/billplz/callback', $params + ['x_signature' => $signature])->assertOk();

        $this->assertSame('paid', Order::where('reference', $reference)->value('status'));
        $this->assertSame('published', Invitation::firstOrFail()->status);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_registration_stores_phone_and_consent_and_customer_record(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Siti Aminah', 'phone' => '0123456789', 'email' => 'Siti@Example.test',
            'password' => 'password123', 'password_confirmation' => 'password123', 'marketing_consent' => true,
        ])->assertCreated()->assertJsonPath('user.phone', '0123456789')->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('customers', ['email' => 'siti@example.test', 'source' => 'register', 'marketing_consent' => true]);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_guest_purchase_can_be_claimed_only_with_reference_and_matching_email(): void
    {
        $reference = $this->checkout();
        $this->fakeBill(paid: true);
        $this->getJson("/api/v1/orders/{$reference}")->assertJsonPath('data.status', 'paid');

        $register = fn (string $email) => $this->postJson('/api/v1/auth/register', [
            'name' => 'Aminah', 'phone' => '0123456789', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->json('token');

        $stranger = $register('stranger@example.test');
        $this->withToken($stranger)->postJson('/api/v1/me/claim', ['reference' => $reference])->assertUnprocessable();
        $this->withToken($stranger)->getJson('/api/v1/me/invitations')->assertJsonCount(0, 'data');

        app('auth')->forgetGuards();
        $owner = $register('aminah@example.test');
        $this->withToken($owner)->getJson('/api/v1/me/invitations')->assertJsonCount(0, 'data');
        app('auth')->forgetGuards();
        $this->withToken($owner)->postJson('/api/v1/me/claim', ['reference' => $reference])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($owner)->getJson('/api/v1/me/invitations')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'published');
        $this->withToken($owner)->postJson('/api/v1/me/claim', ['reference' => $reference])->assertUnprocessable();
    }

    public function test_wishes_are_stored_server_side_for_published_cards_only(): void
    {
        $reference = $this->checkout();
        $slug = Invitation::firstOrFail()->slug;

        $this->postJson("/api/v1/invitations/public/{$slug}/wishes", ['name' => 'Ros', 'message' => 'Tahniah'])->assertNotFound();

        $this->fakeBill(paid: true);
        $this->getJson("/api/v1/orders/{$reference}")->assertJsonPath('data.status', 'paid');

        $this->postJson("/api/v1/invitations/public/{$slug}/wishes", ['name' => 'Ros', 'message' => 'Tahniah'])->assertCreated();
        $this->postJson("/api/v1/invitations/public/{$slug}/wishes", ['name' => '', 'message' => 'x'])->assertUnprocessable();
        $this->getJson("/api/v1/invitations/public/{$slug}/wishes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ros');
    }

    public function test_customer_is_deduplicated_by_email_across_checkouts(): void
    {
        $this->checkout();
        $this->checkout(['customer' => ['email' => 'aminah@example.test', 'marketing_consent' => false]]);

        $this->assertSame(1, Customer::count());
        $this->assertTrue(Customer::firstOrFail()->marketing_consent);
    }

    public function test_drafts_are_private_and_can_be_checked_out_by_owner(): void
    {
        $owner = User::factory()->create(['phone' => '0123456789']);
        $other = User::factory()->create();

        $id = $this->actingAs($owner)->postJson('/api/v1/me/invitations', ['template' => 'seri-kasih', 'content' => ['bride' => 'Alya']])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actingAs($other)->putJson("/api/v1/me/invitations/{$id}", ['content' => ['bride' => 'Hack']])->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/me/invitations')->assertJsonCount(0, 'data');

        $this->fakeBill();
        $this->actingAs($owner)->postJson('/api/v1/checkout', [
            'template' => 'seri-kasih', 'package' => 'starter', 'content' => ['bride' => 'Alya', 'groom' => 'Hakim'], 'invitation_id' => $id,
        ])->assertCreated();

        $this->assertSame(1, Invitation::count());
        $this->assertSame('pending_payment', Invitation::firstOrFail()->status);
        $this->actingAs($owner)->getJson('/api/v1/me/invitations')->assertJsonPath('data.0.order.status', 'pending');
    }

    public function test_admin_endpoints_are_forbidden_for_customers_and_open_to_admins(): void
    {
        $this->checkout();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['password' => 'password123']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->getJson('/api/v1/admin/stats')->assertUnauthorized();
        $this->actingAs($customer)->getJson('/api/v1/admin/customers')->assertForbidden();

        $this->postJson('/api/v1/admin/auth/login', ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'password123'])->assertOk()->assertJsonPath('user.is_admin', true);

        $this->actingAs($admin)->getJson('/api/v1/admin/stats')->assertOk()->assertJsonPath('data.customers_total', 1);
        $this->actingAs($admin)->getJson('/api/v1/admin/customers?consent=1&q=aminah')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.orders_count', 1);
        $this->actingAs($admin)->getJson('/api/v1/admin/orders?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/v1/admin/invitations')->assertOk()->assertJsonMissingPath('data.0.content.donationQr');
    }

    public function test_customer_csv_export_neutralises_formulas_and_respects_consent(): void
    {
        Customer::capture('=cmd|calc', 'evil@example.test', '0123456789', true, 'checkout');
        Customer::capture('No Consent', 'no@example.test', '0123456789', false, 'checkout');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $csv = $this->actingAs($admin)->get('/api/v1/admin/customers/export?consent=1')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString('no@example.test', $csv);
    }
}
