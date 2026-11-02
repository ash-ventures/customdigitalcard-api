<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->boolean('marketing_consent')->default(false)->after('phone');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('full_name', 120);
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('source', 20)->default('checkout');
            $table->timestamps();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->text('payment_url')->nullable()->after('provider_reference');
            $table->timestamp('last_synced_at')->nullable()->after('paid_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['payment_url', 'last_synced_at']);
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'marketing_consent']);
        });
    }
};
