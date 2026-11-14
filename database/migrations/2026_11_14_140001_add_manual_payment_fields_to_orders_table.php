<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('payment_instructions')->nullable()->after('payment_url');
            $table->foreignId('manual_confirmed_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->timestamp('manual_confirmed_at')->nullable()->after('manual_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_confirmed_by');
            $table->dropColumn(['payment_instructions', 'manual_confirmed_at']);
        });
    }
};
