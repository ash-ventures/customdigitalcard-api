<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('provider', 20)->default('chip');
            $table->string('manual_qr_path')->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_type', 20)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
