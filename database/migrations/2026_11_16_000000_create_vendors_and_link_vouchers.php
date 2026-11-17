<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 160)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('package_slug')->constrained()->nullOnDelete();
        });

        // Vendors are snapshotted on the order so reporting survives voucher edits or removal.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('voucher_code')->constrained()->nullOnDelete();
            $table->string('vendor_code', 30)->nullable()->after('vendor_id');
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['vendor_id']);
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn('vendor_code');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });

        Schema::dropIfExists('vendors');
    }
};
