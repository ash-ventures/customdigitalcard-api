<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VendorTest extends TestCase
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
        $bill = 0;
        Http::fake(['www.billplz-sandbox.com/api/v3/bills' => function () use (&$bill) {
            $bill++;

            return Http::response(['id' => "bill_{$bill}", 'url' => "https://www.billplz-sandbox.com/bills/bill_{$bill}", 'paid' => false]);
        }]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function vendor(array $o = []): Vendor
    {
        return Vendor::create($o + ['code' => 'SERIMELATI', 'name' => 'Butik Seri Melati']);
    }

    private function checkoutBody(array $o = []): array
    {
        return $o + [
            'template' => 'seri-kasih',
            'package' => 'starter',
            'content' => ['bride' => 'Alya', 'groom' => 'Hakim', 'date' => now()->addMonth()->toDateString()],
            'customer' => ['name' => 'Aminah Ali', 'phone' => '0123456789', 'email' => 'aminah@example.test', 'marketing_consent' => true],
        ];
    }

    public function test_admin_vendor_crud_and_code_normalisation(): void
    {
        $admin = $this->admin();
        $payload = ['code' => 'seri-melati', 'name' => 'Butik Seri Melati', 'contact_name' => 'Aina', 'contact_phone' => '0123456789', 'contact_email' => 'aina@example.test'];

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/vendors')->assertForbidden();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/vendors', $payload)
            ->assertCreated()->assertJsonPath('data.code', 'SERI-MELATI')->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/admin/vendors', $payload)->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/v1/admin/vendors', ['code' => 'x', 'name' => ''])->assertUnprocessable();

        $this->actingAs($admin)->putJson("/api/v1/admin/vendors/{$id}", $payload + ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/vendors/{$id}")->assertOk();
        $this->assertSame(0, Vendor::count());
    }

    public function test_voucher_can_be_assigned_to_a_vendor_and_checkout_records_it(): void
    {
        $vendor = $this->vendor();
        $voucher = Voucher::create([
            'code' => 'SERI10', 'type' => 'percent', 'value' => 10, 'vendor_id' => $vendor->id,
        ]);

        $this->postJson('/api/v1/vouchers/validate', ['code' => 'seri10', 'package' => 'starter'])
            ->assertOk()
            ->assertJsonPath('data.vendor.code', 'SERIMELATI')
            ->assertJsonPath('data.vendor.name', 'Butik Seri Melati');

        $this->postJson('/api/v1/checkout', $this->checkoutBody(['voucher_code' => 'SERI10']))->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame($vendor->id, $order->vendor_id);
        $this->assertSame('SERIMELATI', $order->vendor_code);
    }

    public function test_vendor_report_counts_only_vendor_orders(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        $voucher = Voucher::create(['code' => 'SERI10', 'type' => 'percent', 'value' => 10, 'vendor_id' => $vendor->id]);

        $this->postJson('/api/v1/checkout', $this->checkoutBody(['voucher_code' => 'SERI10']))->assertCreated();
        // A second order without a voucher must not be attributed to the vendor.
        $this->postJson('/api/v1/checkout', $this->checkoutBody())->assertCreated();

        $vendorOrder = Order::where('vendor_code', 'SERIMELATI')->firstOrFail();
        $vendorOrder->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->actingAs($admin)->getJson('/api/v1/admin/vendors')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'SERIMELATI')
            ->assertJsonPath('data.0.vouchers_count', 1)
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.paid_orders_count', 1)
            ->assertJsonPath('data.0.vouchers.0.code', 'SERI10')
            ->assertJsonPath('data.0.vouchers.0.used_count', 1)
            ->assertJsonPath('meta.paid_orders_count', 1)
            ->assertJsonPath('meta.revenue_sen', $vendorOrder->amount_sen);
    }

    public function test_voucher_admin_exposes_vendor_and_filters_by_vendor(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Voucher::create(['code' => 'SERI10', 'type' => 'percent', 'value' => 10, 'vendor_id' => $vendor->id]);
        Voucher::create(['code' => 'PUBLIC10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'SERI20', 'type' => 'percent', 'value' => 20, 'vendor_id' => $vendor->id,
        ])->assertCreated()->assertJsonPath('data.vendor.code', 'SERIMELATI');

        $this->actingAs($admin)->getJson('/api/v1/admin/vouchers')->assertJsonCount(3, 'data');
        $this->actingAs($admin)->getJson("/api/v1/admin/vouchers?vendor={$vendor->id}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.vendor_id', $vendor->id);

        $this->actingAs($admin)->putJson('/api/v1/admin/vouchers/'.Voucher::where('code', 'SERI20')->value('id'), [
            'code' => 'SERI20', 'type' => 'percent', 'value' => 20, 'vendor_id' => null,
        ])->assertOk()->assertJsonPath('data.vendor', null);
    }

    public function test_used_vendor_is_deactivated_instead_of_deleted(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Voucher::create(['code' => 'SERI10', 'type' => 'percent', 'value' => 10, 'vendor_id' => $vendor->id]);
        $this->postJson('/api/v1/checkout', $this->checkoutBody(['voucher_code' => 'SERI10']))->assertCreated();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/vendors/{$vendor->id}")
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(1, Vendor::count());
        $this->assertSame('SERIMELATI', Order::firstOrFail()->vendor_code);
    }

    public function test_order_list_includes_vendor_details(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor();
        Voucher::create(['code' => 'SERI10', 'type' => 'percent', 'value' => 10, 'vendor_id' => $vendor->id]);
        $this->postJson('/api/v1/checkout', $this->checkoutBody(['voucher_code' => 'SERI10']))->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.vendor_code', 'SERIMELATI')
            ->assertJsonPath('data.0.vendor.name', 'Butik Seri Melati');
    }
}
