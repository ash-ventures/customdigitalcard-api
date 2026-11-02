<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description', 160)->nullable();
            $table->enum('type', ['percent', 'fixed']);
            $table->unsignedInteger('value');
            $table->unsignedInteger('min_amount_sen')->default(0);
            $table->unsignedInteger('max_discount_sen')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->string('package_slug', 40)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('package_id')->constrained()->nullOnDelete();
            $table->string('voucher_code', 40)->nullable()->after('voucher_id');
            $table->unsignedInteger('subtotal_sen')->nullable()->after('voucher_code');
            $table->unsignedInteger('discount_sen')->default(0)->after('subtotal_sen');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['voucher_code', 'subtotal_sen', 'discount_sen']);
        });
        Schema::dropIfExists('vouchers');
    }
};
