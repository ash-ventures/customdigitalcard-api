<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_CATEGORIES = ['Kahwin', 'Event', 'Raya', 'Formal'];

    // Guards keep this safe on databases that already carry some of these columns.
    public function up(): void
    {
        if (! Schema::hasTable('template_categories')) {
            Schema::create('template_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        Schema::table('templates', function (Blueprint $table) {
            if (! Schema::hasColumn('templates', 'code')) {
                $table->string('code', 20)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('templates', 'is_custom')) {
                $table->boolean('is_custom')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('templates', 'background_path')) {
                $table->string('background_path')->nullable()->after('configuration');
            }
        });

        foreach (DB::table('templates')->whereNull('code')->pluck('id') as $id) {
            DB::table('templates')->where('id', $id)->update(['code' => sprintf('TPL-%04d', $id)]);
        }

        $names = collect(self::SYSTEM_CATEGORIES)
            ->merge(DB::table('templates')->distinct()->pluck('category'))
            ->unique()
            ->values();
        foreach ($names as $index => $name) {
            if (DB::table('template_categories')->where('name', $name)->exists()) {
                continue;
            }
            DB::table('template_categories')->insert([
                'name' => $name,
                'sort_order' => $index + 1,
                'is_system' => in_array($name, self::SYSTEM_CATEGORIES, true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'background_path']);
        });
        Schema::dropIfExists('template_categories');
    }
};
