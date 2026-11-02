<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Template;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['slug' => 'seri-kasih', 'name' => 'Seri Bunga Raya', 'category' => 'Kahwin', 'theme' => 'raya', 'description' => 'Bunga raya merah delima di atas krim hangat, lambang semangat dan keberanian cinta.', 'is_premium' => false],
            ['slug' => 'ruang-kita', 'name' => 'Ruang Kita', 'category' => 'Kahwin', 'theme' => 'moden', 'description' => 'Melayu moden: warna teal tengah malam, garisan emas halus dan tipografi bersih.', 'is_premium' => false],
            ['slug' => 'seri-warisan', 'name' => 'Songket Diraja', 'category' => 'Kahwin', 'theme' => 'songket', 'description' => 'Benang emas songket dan pucuk rebung di atas marun yang mewah.', 'is_premium' => false],
            ['slug' => 'taman-senja', 'name' => 'Taman Melur', 'category' => 'Kahwin', 'theme' => 'melur', 'description' => 'Kelopak bunga melur dan zamrud, harum dan suci seperti sanggul pengantin.', 'is_premium' => true],
            ['slug' => 'janji-abadi', 'name' => 'Batik Janji', 'category' => 'Kahwin', 'theme' => 'batik', 'description' => 'Corak batik kawung indigo bersama emas, hasil tangan yang bercerita.', 'is_premium' => true],
            ['slug' => 'pagi-indah', 'name' => 'Wau Pagi', 'category' => 'Kahwin', 'theme' => 'wau', 'description' => 'Wau bulan dan warna matahari terbit pantai timur dengan gaya kontemporari.', 'is_premium' => false],
            ['slug' => 'pasangan-bunga', 'name' => 'Pasangan & Bunga', 'category' => 'Kahwin', 'theme' => 'bunga', 'description' => 'Kelopak bunga merah jambu yang lembut untuk pasangan yang romantik.', 'is_premium' => false],
            ['slug' => 'sakura-rose', 'name' => 'Sakura Rose', 'category' => 'Kahwin', 'theme' => 'sakura', 'description' => 'Bunga sakura merah jambu pada ranting, bingkai renda dan kelopak yang gugur perlahan.', 'is_premium' => false],
            ['slug' => 'taman-peony', 'name' => 'Taman Peony', 'category' => 'Kahwin', 'theme' => 'peony', 'description' => 'Gerbang bunga ros & peony berwarna air, rumput pampas dan kelopak berguguran.', 'is_premium' => false],
            ['slug' => 'bunga-halus', 'name' => 'Bunga Halus', 'category' => 'Kahwin', 'theme' => 'botani', 'description' => 'Lukisan garis bunga merah jambu yang halus di sempadan kad krim.', 'is_premium' => false],
            ['slug' => 'reka-sendiri', 'name' => 'Reka Sendiri', 'category' => 'Kahwin', 'theme' => 'custom', 'description' => 'Kanvas kosong: muat naik reka bentuk anda sendiri sebagai latar kad.', 'is_premium' => false],
            ['slug' => 'raya-ketupat', 'name' => 'Raya Ketupat', 'category' => 'Raya', 'theme' => 'ketupat', 'description' => 'Anyaman ketupat hijau dan emas untuk jemputan rumah terbuka Hari Raya.', 'is_premium' => false],
            ['slug' => 'raya-pelita', 'name' => 'Malam Pelita', 'category' => 'Raya', 'theme' => 'pelita', 'description' => 'Cahaya pelita ungu dan emas bagi malam raya yang syahdu.', 'is_premium' => false],
            ['slug' => 'masjid-nur', 'name' => 'Masjid Nur', 'category' => 'Event', 'theme' => 'masjid', 'description' => 'Kubah, bintang lapan penjuru dan bulan sabit untuk majlis di masjid.', 'is_premium' => false],
            ['slug' => 'bahagia-ceria', 'name' => 'Bahagia Ceria', 'category' => 'Event', 'theme' => 'bahagia', 'description' => 'Warna cerah dan konfeti untuk hari jadi, kesyukuran dan majlis keluarga.', 'is_premium' => false],
            ['slug' => 'majlis-rasmi', 'name' => 'Majlis Rasmi', 'category' => 'Formal', 'theme' => 'formal', 'description' => 'Biru laut dan perak yang kemas untuk majlis korporat dan rasmi.', 'is_premium' => false],
            ['slug' => 'gala-malam', 'name' => 'Gala Malam', 'category' => 'Formal', 'theme' => 'gala', 'description' => 'Hitam dan emas gaya art deco untuk majlis makan malam yang eksklusif.', 'is_premium' => true],
        ];

        foreach ($templates as $index => $template) {
            Template::updateOrCreate(
                ['slug' => $template['slug']],
                [...$template, 'is_active' => true, 'sort_order' => $index + 1],
            );
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
                'features' => ['Semua ciri Asas', 'RSVP dan eksport tetamu', 'Galeri foto dan buku tetamu'],
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
            Package::updateOrCreate(
                ['slug' => $package['slug']],
                [...$package, 'currency' => 'MYR', 'is_active' => true, 'sort_order' => $index + 1],
            );
        }
    }
}
