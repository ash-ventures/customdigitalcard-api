<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('photobook_enabled')->default(false)->after('guestbook_enabled');
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('photobook_enabled')->default(false)->after('guestbook_enabled');
        });

        $package = DB::table('packages')->where('slug', 'premium-plus')->first();
        if ($package) {
            $features = json_decode((string) $package->features, true);
            $features = is_array($features) ? $features : [];
            if (! in_array('Fotobuku Polaroid & album langsung', $features, true)) {
                $features[] = 'Fotobuku Polaroid & album langsung';
            }
            DB::table('packages')->where('id', $package->id)->update([
                'photobook_enabled' => true,
                'features' => json_encode(array_values($features), JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('invitations')
            ->join('packages', 'packages.id', '=', 'invitations.package_id')
            ->where('packages.photobook_enabled', true)
            ->update(['invitations.photobook_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('photobook_enabled'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('photobook_enabled'));
    }
};
