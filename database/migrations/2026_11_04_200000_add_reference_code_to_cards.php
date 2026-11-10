<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('card_designs', 'code')) {
            Schema::table('card_designs', function (Blueprint $table) {
                $table->string('code', 20)->nullable()->unique()->after('id');
            });
        }

        foreach (DB::table('card_designs')->whereNull('code')->pluck('id') as $id) {
            DB::table('card_designs')->where('id', $id)->update(['code' => sprintf('CD-%04d', $id)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('card_designs', 'code')) {
            Schema::table('card_designs', function (Blueprint $table) {
                $table->dropUnique(['code']);
                $table->dropColumn('code');
            });
        }
    }
};
