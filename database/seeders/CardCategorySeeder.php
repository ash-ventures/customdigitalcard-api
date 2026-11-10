<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CardCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'kahwin', 'name' => 'Wedding'],
            ['slug' => 'event', 'name' => 'Event'],
            ['slug' => 'raya', 'name' => 'Raya'],
            ['slug' => 'formal', 'name' => 'Formal'],
        ];

        foreach ($categories as $category) {
            DB::table('card_categories')->updateOrInsert(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
