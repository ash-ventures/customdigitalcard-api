<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('theme');
            $table->string('description', 500);
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price_sen');
            $table->char('currency', 3)->default('MYR');
            $table->unsignedSmallInteger('validity_days_after_event');
            $table->json('features');
            $table->unsignedSmallInteger('gallery_limit')->default(0);
            $table->boolean('rsvp_enabled')->default(false);
            $table->boolean('premium_templates')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('status')->default('draft');
            $table->json('content');
            $table->boolean('rsvp_enabled')->default(false);
            $table->timestamp('rsvp_deadline')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->unsignedInteger('amount_sen');
            $table->char('currency', 3)->default('MYR');
            $table->string('status')->default('pending');
            $table->string('provider')->default('billplz');
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_reference')->unique();
            $table->string('processing_status')->default('received');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rsvp_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->string('guest_name', 100);
            $table->string('attendance', 20);
            $table->unsignedTinyInteger('pax')->default(1);
            $table->string('contact', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('submission_key')->nullable();
            $table->timestamps();

            $table->unique(['invitation_id', 'submission_key']);
            $table->index(['invitation_id', 'attendance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_responses');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('templates');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
