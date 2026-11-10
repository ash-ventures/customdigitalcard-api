<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('templates', 'card_category_id')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->foreignId('card_category_id')->nullable()->constrained('card_categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('templates', 'card_category_id')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->dropConstrainedForeignId('card_category_id');
            });
        }
    }
};
