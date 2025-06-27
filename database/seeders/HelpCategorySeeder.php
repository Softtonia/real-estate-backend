<?php

namespace Database\Seeders;

use App\Models\HelpCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HelpCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'User Profile',
            'Property Management',
            'Response Management',
            'Orders & Services',
        ];

        foreach ($categories as $name) {
            HelpCategory::create([
                'name' => $name
            ]);
        }
    }
}
