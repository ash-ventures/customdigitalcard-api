<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Guards keep this safe on databases where these objects already exist.
    public function up(): void
    {
        if (! Schema::hasTable('card_categories')) {
            Schema::create('card_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug')->unique();
                $table->string('description', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('card_designs')) {
            Schema::create('card_designs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('card_categories')->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->string('name', 120);
                $table->string('slug')->unique();
                $table->string('description', 500)->nullable();
                $table->string('thumbnail_path');
                $table->string('background_path');
                $table->string('design_type', 40)->default('static_image_with_text');
                $table->string('orientation', 20)->default('portrait');
                $table->unsignedSmallInteger('canvas_width');
                $table->unsignedSmallInteger('canvas_height');
                $table->json('configuration');
                $table->string('status', 20)->default('draft');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'category_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('invitations', 'card_design_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->foreignId('card_design_id')->nullable()->after('template_id')->constrained('card_designs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invitations', 'card_design_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('card_design_id');
            });
        }
        Schema::dropIfExists('card_designs');
        Schema::dropIfExists('card_categories');
    }
};
