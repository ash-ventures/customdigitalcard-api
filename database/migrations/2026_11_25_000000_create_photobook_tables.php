<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photobook_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_name', 60);
            $table->string('relationship', 40)->nullable();
            $table->string('note', 160)->nullable();
            $table->string('image_path', 255);
            $table->string('mime', 20)->default('image/jpeg');
            $table->unsignedSmallInteger('width')->default(720);
            $table->unsignedSmallInteger('height')->default(900);
            $table->unsignedInteger('likes_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->index(['invitation_id', 'is_featured', 'created_at']);
        });

        Schema::create('photobook_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photobook_photo_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['photobook_photo_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photobook_likes');
        Schema::dropIfExists('photobook_photos');
    }
};
