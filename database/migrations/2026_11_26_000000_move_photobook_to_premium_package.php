<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FEATURE = 'Fotobuku Polaroid & album langsung';

    private const SLUGS = ['premium', 'premium-plus'];

    /**
     * Fotobuku belongs to the Istimewa (premium) package and above, so cards on those packages get
     * the Polaroid camera and live album as soon as this migration runs.
     */
    public function up(): void
    {
        foreach (DB::table('packages')->whereIn('slug', self::SLUGS)->orderBy('id')->get() as $package) {
            $features = json_decode((string) $package->features, true);
            $features = is_array($features) ? $features : [];
            if (! in_array(self::FEATURE, $features, true)) {
                $features[] = self::FEATURE;
            }

            DB::table('packages')->where('id', $package->id)->update([
                'photobook_enabled' => true,
                'features' => json_encode(array_values($features), JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('invitations')
            ->join('packages', 'packages.id', '=', 'invitations.package_id')
            ->whereIn('packages.slug', self::SLUGS)
            ->update(['invitations.photobook_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('packages')->where('slug', 'premium')->update(['photobook_enabled' => false]);

        DB::table('invitations')
            ->join('packages', 'packages.id', '=', 'invitations.package_id')
            ->where('packages.slug', 'premium')
            ->update(['invitations.photobook_enabled' => false]);
    }
};
