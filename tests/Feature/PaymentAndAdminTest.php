<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentAndAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config([
            'services.payments.provider' => 'billplz',
            'services.billplz.api_key' => 'test-key',
            'services.billplz.collection_id' => 'col123',
            'services.billplz.x_signature_key' => 'sig-secret',
            'services.billplz.base_url' => 'https://www.billplz-sandbox.com/api/v3',
            'services.billplz.frontend_url' => 'http://127.0.0.1:3000',
        ]);
    }

    private function configureChip(): void
    {
        config([
            'services.payments.provider' => 'chip',
            'services.chip.api_key' => 'chip-test-key',
            'services.chip.brand_id' => 'brand-test-id',
            'services.chip.base_url' => 'https://gate.chip-in.asia/api/v1',
            'services.chip.frontend_url' => 'http://127.0.0.1:3000',
            'services.chip.success_callback_url' => null,
        ]);
    }

    private function chipPurchase(string $id = 'purchase-1', int $amount = 1900, string $status = 'paid'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'purchase' => [
                'total' => $amount,
                'currency' => 'MYR',
                'products' => [['name' => 'JemputanKu - Pakej Asas', 'price' => $amount]],
            ],
        ];
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

    public function test_chip_checkout_creates_purchase_using_api_credentials_and_redirects_to_checkout_url(): void
    {
        $this->configureChip();
        config(['services.chip.success_callback_url' => 'https://api.example.test/api/v1/payments/chip/callback']);
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase-1',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-1/',
            ], 201),
        ]);

        $response = $this->postJson('/api/v1/checkout', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.payment_url', 'https://gate.chip-in.asia/p/purchase-1/')
            ->assertJsonPath('data.payment_provider', 'chip');
        $reference = $response->json('data.order_reference');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/purchases/')
            && $request->hasHeader('Authorization', 'Bearer chip-test-key')
            && $request['brand_id'] === 'brand-test-id'
            && $request['client']['email'] === 'aminah@example.test'
            && (int) $request['purchase']['products'][0]['price'] === 1900
            && $request['success_callback'] === 'https://api.example.test/api/v1/payments/chip/callback'
            && str_contains($request['success_redirect'], '/bayaran/status?ref='.$reference));

        $this->assertDatabaseHas('orders', [
            'reference' => $reference,
            'status' => 'pending',
            'provider' => 'chip',
            'provider_reference' => 'purchase-1',
        ]);
    }

    public function test_chip_purchase_is_verified_server_side_before_publishing(): void
    {
        $this->configureChip();
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase-1',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-1/',
            ], 201),
            'gate.chip-in.asia/api/v1/purchases/purchase-1/' => Http::response($this->chipPurchase(), 200),
        ]);

        $reference = $this->postJson('/api/v1/checkout', $this->payload())
            ->assertCreated()
            ->json('data.order_reference');
        $slug = Invitation::firstOrFail()->slug;

        $this->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invitation.public_path', "/jemputan/{$slug}");

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/purchases/purchase-1/'));
    }

    public function test_chip_amount_mismatch_does_not_publish(): void
    {
        $this->configureChip();
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase-1',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-1/',
            ], 201),
            'gate.chip-in.asia/api/v1/purchases/purchase-1/' => Http::response($this->chipPurchase(amount: 100), 200),
        ]);

        $reference = $this->postJson('/api/v1/checkout', $this->payload())
            ->assertCreated()
            ->json('data.order_reference');

        $this->getJson("/api/v1/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invitation.public_path', null);
    }

    public function test_chip_callback_requires_rsa_signature_and_settles_server_verified_purchase(): void
    {
        $this->configureChip();
        $privateKey = file_get_contents(base_path('tests/Fixtures/chip-callback-private.pem'));
        $this->assertIsString($privateKey);
        $keyPair = openssl_pkey_get_private($privateKey);
        $this->assertNotFalse($keyPair);
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $keyDetails['key'];
        $this->assertIsString($publicKey);

        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'purchase-1',
                'checkout_url' => 'https://gate.chip-in.asia/p/purchase-1/',
            ], 201),
            'gate.chip-in.asia/api/v1/public_key/' => Http::response(json_encode($publicKey), 200),
            'gate.chip-in.asia/api/v1/purchases/purchase-1/' => Http::response($this->chipPurchase(), 200),
        ]);
        Cache::forget('chip.collect.public_key');

        $reference = $this->postJson('/api/v1/checkout', $this->payload())
            ->assertCreated()
            ->json('data.order_reference');
        $rawBody = json_encode(['id' => 'purchase-1', 'status' => 'paid', 'event_type' => 'purchase.paid']);
        $this->assertIsString($rawBody);
        $this->assertTrue(openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => base64_encode($signature)];

        $this->call('POST', '/api/v1/payments/chip/callback', [], [], [], array_replace($headers, ['HTTP_X_SIGNATURE' => 'invalid']), $rawBody)
            ->assertForbidden();
        $this->call('POST', '/api/v1/payments/chip/callback', [], [], [], $headers, $rawBody)->assertOk();
        $this->call('POST', '/api/v1/payments/chip/callback', [], [], [], $headers, $rawBody)->assertOk();

        $this->assertSame('paid', Order::where('reference', $reference)->value('status'));
        $this->assertSame('published', Invitation::firstOrFail()->status);
        $this->assertDatabaseHas('payment_webhook_events', [
            'provider' => 'chip',
            'event_reference' => 'chip:purchase-1:purchase.paid',
            'processing_status' => 'processed',
        ]);
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
        $invitation = Invitation::firstOrFail();
        $invitation->update(['content' => [...$invitation->content, 'couplePhoto' => 'data:image/jpeg;base64,AAAA']]);
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
        $this->actingAs($admin)->getJson('/api/v1/admin/invitations')
            ->assertOk()
            ->assertJsonMissingPath('data.0.content.donationQr')
            ->assertJsonMissingPath('data.0.content.couplePhoto')
            ->assertJsonPath('data.0.has_couple_photo', true);
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

    public function test_admin_can_manage_paid_invitations_and_view_their_guest_responses(): void
    {
        $this->checkout();
        $invitation = Invitation::firstOrFail();
        $invitation->wishes()->create(['name' => 'Guest', 'message' => 'Congratulations']);
        $invitation->rsvpResponses()->create(['guest_name' => 'Guest', 'attendance' => 'attending', 'pax' => 2]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $customer = User::factory()->create();
        $url = "/api/v1/admin/invitations/{$invitation->id}";

        $this->getJson("{$url}/responses")->assertUnauthorized();
        $this->actingAs($customer)->getJson("{$url}/responses")->assertForbidden();
        $this->actingAs($customer)->patchJson($url, ['expires_at' => null])->assertForbidden();
        $this->actingAs($admin)->getJson("{$url}/responses")
            ->assertOk()->assertJsonPath('data.summary.attending_guests', 2)
            ->assertJsonPath('data.wishes.0.message', 'Congratulations');
        $this->actingAs($admin)->patchJson($url, ['status' => 'published'])->assertUnprocessable();

        $invitation->orders()->firstOrFail()->update(['status' => 'paid']);
        $invitation->update(['status' => 'published']);
        $this->actingAs($admin)->patchJson($url, ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.public_path', null);
        $this->getJson("/api/v1/invitations/public/{$invitation->slug}")->assertNotFound();
        $invitation->update(['user_id' => $customer->id]);
        $this->actingAs($customer)->deleteJson("/api/v1/me/invitations/{$invitation->id}")->assertUnprocessable();
        $this->actingAs($customer)->getJson("/api/v1/me/invitations/{$invitation->id}/responses")
            ->assertOk()->assertJsonPath('data.summary.wishes_total', 1);
        $this->actingAs($customer)->postJson('/api/v1/checkout', [
            'template' => 'seri-kasih', 'package' => 'starter',
            'content' => ['bride' => 'Alya'], 'invitation_id' => $invitation->id,
        ])->assertNotFound();
        $this->actingAs($admin)->patchJson($url, ['status' => 'published', 'expires_at' => '2030-01-01T00:00:00Z'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($admin)->patchJson($url, ['status' => 'draft'])->assertUnprocessable();
    }

    public function test_package_management_is_admin_only_and_seed_does_not_overwrite_changes(): void
    {
        $package = Package::where('slug', 'starter')->firstOrFail();
        $customer = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $changes = [
            'name' => 'Custom', 'price_sen' => 2750, 'features' => ['Online invitation', 'Guestbook'],
            'validity_days_after_event' => 60, 'rsvp_enabled' => true, 'guestbook_enabled' => false,
            'premium_templates' => true, 'is_active' => true, 'sort_order' => 1,
        ];
        $url = "/api/v1/admin/packages/{$package->id}";
        $this->putJson($url, $changes)->assertUnauthorized();
        $this->actingAs($customer)->putJson($url, $changes)->assertForbidden();
        $this->actingAs($admin)->putJson($url, [...$changes, 'price_sen' => -1])->assertUnprocessable();
        $this->actingAs($admin)->putJson($url, $changes)->assertOk()->assertJsonPath('data.price_sen', 2750);
        $this->seed();
        $this->getJson('/api/v1/packages')->assertJsonFragment(['name' => 'Custom', 'price_sen' => 2750]);
        $this->assertFalse($package->fresh()->guestbook_enabled);
    }

    public function test_checkout_snapshots_benefits_and_rejects_stale_package_version(): void
    {
        $package = Package::where('slug', 'starter')->firstOrFail();
        $package->update(['rsvp_enabled' => true, 'guestbook_enabled' => true]);
        $version = $package->fresh()->catalogVersion();
        $reference = $this->checkout(['package_version' => $version]);
        $invitation = Invitation::firstOrFail();
        $package->update(['price_sen' => 2500, 'rsvp_enabled' => false, 'guestbook_enabled' => false, 'validity_days_after_event' => 5]);
        $this->postJson('/api/v1/checkout', $this->payload(['package_version' => $version]))->assertUnprocessable();
        $this->fakeBill(paid: true);
        $this->getJson("/api/v1/orders/{$reference}")->assertJsonPath('data.status', 'paid');
        $invitation->refresh();
        $this->assertTrue($invitation->rsvp_enabled);
        $this->assertTrue($invitation->guestbook_enabled);
        $this->assertSame(CarbonImmutable::parse($invitation->content['date'])->addDays(30)->toDateString(), $invitation->expires_at->toDateString());
        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/wishes", ['name' => 'Guest', 'message' => 'Best wishes'])->assertCreated();
    }

    public function test_guestbook_can_be_disabled_for_future_purchases(): void
    {
        Package::where('slug', 'starter')->update(['guestbook_enabled' => false]);
        $reference = $this->checkout();
        $this->fakeBill(paid: true);
        $this->getJson("/api/v1/orders/{$reference}")->assertJsonPath('data.status', 'paid');
        $invitation = Invitation::firstOrFail();
        $this->getJson("/api/v1/invitations/public/{$invitation->slug}")->assertJsonPath('data.guestbook_enabled', false);
        $this->getJson("/api/v1/invitations/public/{$invitation->slug}/wishes")->assertNotFound();
        $this->postJson("/api/v1/invitations/public/{$invitation->slug}/wishes", ['name' => 'Guest', 'message' => 'Best wishes'])->assertNotFound();
    }
}
