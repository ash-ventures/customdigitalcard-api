<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('message', 240);
            $table->timestamps();
            $table->index(['invitation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
    }
};
