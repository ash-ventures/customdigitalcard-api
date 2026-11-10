<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('guestbook_enabled')->default(true);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('package_snapshot')->nullable();
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('guestbook_enabled')->default(true);
        });

        DB::table('orders')->where('status', 'pending')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                $package = DB::table('packages')->where('id', $order->package_id)->first();
                if (! $package) {
                    throw new RuntimeException("Package missing for pending order {$order->id}.");
                }
                DB::table('orders')->where('id', $order->id)->update(['package_snapshot' => json_encode([
                    'name' => $package->name,
                    'price_sen' => $order->subtotal_sen,
                    'currency' => $order->currency,
                    'validity_days_after_event' => $package->validity_days_after_event,
                    'features' => json_decode($package->features, true, flags: JSON_THROW_ON_ERROR),
                    'rsvp_enabled' => (bool) $package->rsvp_enabled,
                    'guestbook_enabled' => true,
                    'premium_templates' => (bool) $package->premium_templates,
                ], JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('guestbook_enabled'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('package_snapshot'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('guestbook_enabled'));
    }
};
