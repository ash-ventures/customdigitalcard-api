<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Template;
use App\Models\TemplateCategory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CardCategorySeeder::class]);

        $templates = [
            ['slug' => 'seri-kasih', 'name' => 'Seri Bunga Raya', 'category' => 'Kahwin', 'theme' => 'raya', 'description' => 'Bunga raya merah delima di atas krim hangat, lambang semangat dan keberanian cinta.', 'is_premium' => false],
            ['slug' => 'ruang-kita', 'name' => 'Ruang Kita', 'category' => 'Kahwin', 'theme' => 'moden', 'description' => 'Melayu moden: warna teal tengah malam, garisan emas halus dan tipografi bersih.', 'is_premium' => false],
            ['slug' => 'seri-warisan', 'name' => 'Songket Diraja', 'category' => 'Kahwin', 'theme' => 'songket', 'description' => 'Benang emas songket dan pucuk rebung di atas marun yang mewah.', 'is_premium' => false],
            ['slug' => 'taman-senja', 'name' => 'Taman Melur', 'category' => 'Kahwin', 'theme' => 'melur', 'description' => 'Kelopak bunga melur dan zamrud, harum dan suci seperti sanggul pengantin.', 'is_premium' => true],
            ['slug' => 'janji-abadi', 'name' => 'Batik Janji', 'category' => 'Kahwin', 'theme' => 'batik', 'description' => 'Corak batik kawung indigo bersama emas, hasil tangan yang bercerita.', 'is_premium' => true],
            ['slug' => 'pagi-indah', 'name' => 'Wau Pagi', 'category' => 'Kahwin', 'theme' => 'wau', 'description' => 'Wau bulan dan warna matahari terbit pantai timur dengan gaya kontemporari.', 'is_premium' => false],
            ['slug' => 'pasangan-bunga', 'name' => 'Pasangan & Bunga', 'category' => 'Kahwin', 'theme' => 'bunga', 'description' => 'Kelopak bunga merah jambu yang lembut untuk pasangan yang romantik.', 'is_premium' => false],
            ['slug' => 'sakura-rose', 'name' => 'Watercolor Blossom', 'category' => 'Kahwin', 'theme' => 'sakura', 'description' => 'Bunga sakura merah jambu berus-air pada ranting, bingkai renda dan kelopak yang gugur perlahan.', 'is_premium' => false],
            ['slug' => 'taman-peony', 'name' => 'Luxury Pink Peony', 'category' => 'Kahwin', 'theme' => 'peony', 'description' => 'Gerbang bunga ros dan peony berwarna air, rumput pampas dan kelopak berguguran.', 'is_premium' => false],
            ['slug' => 'bunga-halus', 'name' => 'Minimalist Floral', 'category' => 'Kahwin', 'theme' => 'botani', 'description' => 'Lukisan garis bunga merah jambu yang halus pada bingkai krim dengan ruang putih yang lapang.', 'is_premium' => false],
            ['slug' => 'mawar-sutra', 'name' => 'Vintage Floral Arch', 'category' => 'Kahwin', 'theme' => 'mawar', 'description' => 'Kertas ivory bertekstur dengan gerbang keemasan, mawar lembut dan nama pengantin berskrip.', 'is_premium' => false],
            ['slug' => 'embun-pagi', 'name' => 'English Garden', 'category' => 'Kahwin', 'theme' => 'embun', 'description' => 'Lakaran bunga pastel dan dedaun hijau sage dalam bingkai taman Inggeris yang ringan.', 'is_premium' => false],
            ['slug' => 'senja-asmara', 'name' => 'Romantic Rose Garden', 'category' => 'Kahwin', 'theme' => 'senja', 'description' => 'Mawar merah jambu di sudut atas dan taman bunga yang mekar di bahagian bawah kad.', 'is_premium' => false],
            ['slug' => 'burgundy-rose', 'name' => 'Burgundy Rose Luxury', 'category' => 'Kahwin', 'theme' => 'burgundy', 'description' => 'Mawar burgundy, dedaun malap dan aksen champagne pada kertas ivory yang mewah.', 'is_premium' => true],
            ['slug' => 'wildflower-romance', 'name' => 'Wildflower Romance', 'category' => 'Kahwin', 'theme' => 'wildflower', 'description' => 'Bunga liar kecil dan ranting halus membingkai jemputan seperti taman yang sedang mekar.', 'is_premium' => false],
            ['slug' => 'reka-sendiri', 'name' => 'Reka Sendiri', 'category' => 'Kahwin', 'theme' => 'custom', 'description' => 'Kanvas kosong: muat naik reka bentuk anda sendiri sebagai latar kad.', 'is_premium' => false],
            ['slug' => 'raya-ketupat', 'name' => 'Raya Ketupat', 'category' => 'Raya', 'theme' => 'ketupat', 'description' => 'Anyaman ketupat hijau dan emas untuk jemputan rumah terbuka Hari Raya.', 'is_premium' => false],
            ['slug' => 'raya-pelita', 'name' => 'Malam Pelita', 'category' => 'Raya', 'theme' => 'pelita', 'description' => 'Cahaya pelita ungu dan emas bagi malam raya yang syahdu.', 'is_premium' => false],
            ['slug' => 'masjid-nur', 'name' => 'Masjid Nur', 'category' => 'Event', 'theme' => 'masjid', 'description' => 'Kubah, bintang lapan penjuru dan bulan sabit untuk majlis di masjid.', 'is_premium' => false],
            ['slug' => 'bahagia-ceria', 'name' => 'Bahagia Ceria', 'category' => 'Event', 'theme' => 'bahagia', 'description' => 'Warna cerah dan konfeti untuk hari jadi, kesyukuran dan majlis keluarga.', 'is_premium' => false],
            ['slug' => 'majlis-rasmi', 'name' => 'Majlis Rasmi', 'category' => 'Formal', 'theme' => 'formal', 'description' => 'Biru laut dan perak yang kemas untuk majlis korporat dan rasmi.', 'is_premium' => false],
            ['slug' => 'gala-malam', 'name' => 'Gala Malam', 'category' => 'Formal', 'theme' => 'gala', 'description' => 'Hitam dan emas gaya art deco untuk majlis makan malam yang eksklusif.', 'is_premium' => true],
        ];

        foreach ($templates as $index => $template) {
            $model = Template::firstOrNew(['slug' => $template['slug']]);
            // Admin-managed fields (premium, active, order) are only seeded for new cards.
            $model->fill($model->exists ? ['name' => $template['name'], 'description' => $template['description'], 'theme' => $template['theme']] : [...$template, 'is_active' => true, 'sort_order' => $index + 1]);
            $model->save();
            if (! $model->code) {
                $model->update(['code' => Template::codeFor($model->id)]);
            }
        }

        foreach (['Kahwin', 'Event', 'Raya', 'Formal'] as $order => $name) {
            TemplateCategory::firstOrCreate(['name' => $name], ['sort_order' => $order + 1, 'is_system' => true]);
        }

        $packages = [
            [
                'slug' => 'starter',
                'name' => 'Asas',
                'price_sen' => 1900,
                'validity_days_after_event' => 30,
                'features' => ['Kad digital unik', 'Peta lokasi dan kod QR', 'Kiraan detik majlis'],
                'gallery_limit' => 0,
                'rsvp_enabled' => false,
                'premium_templates' => false,
            ],
            [
                'slug' => 'premium',
                'name' => 'Istimewa',
                'price_sen' => 3900,
                'validity_days_after_event' => 30,
                'features' => ['Semua ciri Asas', 'RSVP dan eksport tetamu', 'Buku tetamu'],
                'gallery_limit' => 12,
                'rsvp_enabled' => true,
                'premium_templates' => true,
            ],
            [
                'slug' => 'premium-plus',
                'name' => 'Seri',
                'price_sen' => 6900,
                'validity_days_after_event' => 90,
                'features' => ['Semua ciri Istimewa', 'Tema dan warna eksklusif', 'Tempoh kad lebih panjang'],
                'gallery_limit' => 24,
                'rsvp_enabled' => true,
                'premium_templates' => true,
            ],
        ];

        foreach ($packages as $index => $package) {
            Package::firstOrCreate(
                ['slug' => $package['slug']],
                [...$package, 'currency' => 'MYR', 'is_active' => true, 'sort_order' => $index + 1],
            );
        }
    }
}
