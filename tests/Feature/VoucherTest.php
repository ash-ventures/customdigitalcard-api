<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoucherTest extends TestCase
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
        Http::fake(['www.billplz-sandbox.com/api/v3/bills' => Http::response(['id' => 'bill_1', 'url' => 'https://www.billplz-sandbox.com/bills/bill_1', 'paid' => false])]);
    }

    private function voucher(array $o = []): Voucher
    {
        return Voucher::create($o + ['code' => 'RAYA10', 'type' => 'percent', 'value' => 10]);
    }

    private function body(array $o = []): array
    {
        return $o + [
            'template' => 'seri-kasih',
            'package' => 'starter',
            'content' => ['bride' => 'Alya', 'groom' => 'Hakim', 'date' => now()->addMonth()->toDateString()],
            'customer' => ['name' => 'Aminah Ali', 'phone' => '0123456789', 'email' => 'aminah@example.test', 'marketing_consent' => true],
        ];
    }

    public function test_validate_percent_and_fixed(): void
    {
        $this->voucher();
        $this->voucher(['code' => 'RM5', 'type' => 'fixed', 'value' => 500]);

        $this->postJson('/api/v1/vouchers/validate', ['code' => ' raya10 ', 'package' => 'starter'])
            ->assertOk()->assertJsonPath('data.discount_sen', 190)->assertJsonPath('data.total_sen', 1710);
        $this->postJson('/api/v1/vouchers/validate', ['code' => 'rm5', 'package' => 'starter'])
            ->assertOk()->assertJsonPath('data.total_sen', 1400);
        $this->postJson('/api/v1/vouchers/validate', ['code' => 'NOPE', 'package' => 'starter'])->assertUnprocessable();
    }

    public function test_rejects_expired_inactive_and_min_amount(): void
    {
        $this->voucher(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->voucher(['code' => 'OFF', 'is_active' => false]);
        $this->voucher(['code' => 'BIG', 'min_amount_sen' => 5000]);
        $this->voucher(['code' => 'PREM', 'package_slug' => 'premium']);

        foreach (['OLD', 'OFF', 'BIG', 'PREM'] as $code) {
            $this->postJson('/api/v1/vouchers/validate', ['code' => $code, 'package' => 'starter'])->assertUnprocessable();
        }
    }

    public function test_checkout_recomputes_amount_server_side_and_enforces_usage_limit(): void
    {
        $this->voucher(['usage_limit' => 1]);

        $this->postJson('/api/v1/checkout', $this->body(['voucher_code' => 'raya10', 'amount_sen' => 1]))->assertCreated()
            ->assertJsonPath('data.amount_sen', 1710);
        $order = Order::firstOrFail();
        $this->assertSame(1710, $order->amount_sen);
        $this->assertSame(190, $order->discount_sen);
        $this->assertSame('RAYA10', $order->voucher_code);

        $this->postJson('/api/v1/checkout', $this->body(['voucher_code' => 'RAYA10']))->assertUnprocessable();
    }

    public function test_per_customer_limit(): void
    {
        $this->voucher(['per_customer_limit' => 1]);
        $this->postJson('/api/v1/checkout', $this->body(['voucher_code' => 'RAYA10']))->assertCreated();
        $this->postJson('/api/v1/checkout', $this->body(['voucher_code' => 'RAYA10']))->assertUnprocessable();
    }

    public function test_discount_never_leaves_total_below_gateway_minimum(): void
    {
        $this->voucher(['code' => 'ALMOST', 'type' => 'fixed', 'value' => 1850]);
        $this->postJson('/api/v1/vouchers/validate', ['code' => 'ALMOST', 'package' => 'starter'])
            ->assertOk()->assertJsonPath('data.total_sen', 100);
    }

    public function test_full_discount_publishes_without_gateway(): void
    {
        $this->voucher(['code' => 'FREE', 'value' => 100]);

        $ref = $this->postJson('/api/v1/checkout', $this->body(['voucher_code' => 'FREE']))
            ->assertCreated()->assertJsonPath('data.payment_url', null)->assertJsonPath('data.status', 'paid')
            ->json('data.order_reference');

        Http::assertNothingSent();
        $this->getJson("/api/v1/orders/{$ref}")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.discount_sen', 1900)
            ->assertJsonPath('data.invitation.public_path', fn ($p) => is_string($p));
    }

    public function test_admin_voucher_crud(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $payload = ['code' => 'baru-20', 'type' => 'percent', 'value' => 20, 'description' => 'Test'];

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/vouchers')->assertForbidden();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', $payload)
            ->assertCreated()->assertJsonPath('data.code', 'BARU-20')->json('data.id');
        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', $payload)->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', ['code' => 'bad', 'type' => 'percent', 'value' => 150])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/v1/admin/vouchers/{$id}", $payload + ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->getJson('/api/v1/admin/vouchers')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/vouchers/{$id}")->assertOk();
        $this->assertSame(0, Voucher::count());
    }
}
