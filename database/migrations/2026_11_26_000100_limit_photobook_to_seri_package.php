<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FEATURE = 'Fotobuku Polaroid & album langsung';

    /**
     * Polaroid Fotobuku is exclusive to the Seri package (premium-plus). Istimewa keeps RSVP and
     * the guestbook but loses Fotobuku, so the checkout picker only offers Seri for that feature.
     */
    public function up(): void
    {
        $premium = DB::table('packages')->where('slug', 'premium')->first();
        if ($premium) {
            $features = json_decode((string) $premium->features, true);
            $features = is_array($features) ? $features : [];
            $features = array_values(array_filter($features, fn ($feature) => $feature !== self::FEATURE));

            DB::table('packages')->where('id', $premium->id)->update([
                'photobook_enabled' => false,
                'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('packages')->where('slug', 'premium-plus')->update(['photobook_enabled' => true]);

        DB::table('invitations')
            ->join('packages', 'packages.id', '=', 'invitations.package_id')
            ->where('packages.slug', '!=', 'premium-plus')
            ->update(['invitations.photobook_enabled' => false]);
    }

    public function down(): void
    {
        $premium = DB::table('packages')->where('slug', 'premium')->first();
        if ($premium) {
            $features = json_decode((string) $premium->features, true);
            $features = is_array($features) ? $features : [];
            if (! in_array(self::FEATURE, $features, true)) {
                $features[] = self::FEATURE;
            }

            DB::table('packages')->where('id', $premium->id)->update([
                'photobook_enabled' => true,
                'features' => json_encode(array_values($features), JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('invitations')
            ->join('packages', 'packages.id', '=', 'invitations.package_id')
            ->whereIn('packages.slug', ['premium', 'premium-plus'])
            ->update(['invitations.photobook_enabled' => true]);
    }
};
