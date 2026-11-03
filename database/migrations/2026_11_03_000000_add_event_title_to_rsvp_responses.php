<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvp_responses', function (Blueprint $table): void {
            $table->string('event_title', 120)->nullable()->after('attendance');
        });
    }

    public function down(): void
    {
        Schema::table('rsvp_responses', function (Blueprint $table): void {
            $table->dropColumn('event_title');
        });
    }
};
