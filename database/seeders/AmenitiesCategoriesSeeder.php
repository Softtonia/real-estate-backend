<?php

namespace Database\Seeders;

use App\Models\AmenitiesCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AmenitiesCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Scenic views',
                'display_amenities_categories_order' => 1,
                'icon_id' => 1,
                'icon_name' => 'Scenic views',
                'icon_css' => 'Scenic views-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bathroom',
                'display_amenities_categories_order' => 2,
                'icon_id' => 2,
                'icon_name' => 'Bathroom',
                'icon_css' => 'Bathroom-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bedroom and laundry',
                'display_amenities_categories_order' => 3,
                'icon_id' => 3,
                'icon_name' => 'Bedroom and laundry',
                'icon_css' => 'Bedroom and laundry-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Family',
                'display_amenities_categories_order' => 4,
                'icon_id' => 5,
                'icon_name' => 'Family',
                'icon_css' => 'Family-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Heating and cooling',
                'display_amenities_categories_order' => 5,
                'icon_id' => 6,
                'icon_name' => 'Heating and cooling',
                'icon_css' => 'Heating and cooling-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Home safety',
                'display_amenities_categories_order' => 6,
                'icon_id' => 7,
                'icon_name' => 'Home safety',
                'icon_css' => 'Home safety-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Internet and office',
                'display_amenities_categories_order' => 7,
                'icon_id' => 8,
                'icon_name' => 'Internet and office',
                'icon_css' => 'Internet and office-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Kitchen and dining',
                'display_amenities_categories_order' => 8,
                'icon_id' => 9,
                'icon_name' => 'Kitchen and dining',
                'icon_css' => 'Kitchen and dining-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Location features',
                'display_amenities_categories_order' => 9,
                'icon_id' => 10,
                'icon_name' => 'Location features',
                'icon_css' => 'Location features-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Outdoor',
                'display_amenities_categories_order' => 10,
                'icon_id' => 11,
                'icon_name' => 'Outdoor',
                'icon_css' => 'Outdoor-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Parking and facilities',
                'display_amenities_categories_order' => 11,
                'icon_id' => 12,
                'icon_name' => 'Parking and facilities',
                'icon_css' => 'Parking and facilities-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Services',
                'display_amenities_categories_order' => 12,
                'icon_id' => 13,
                'icon_name' => 'Services',
                'icon_css' => 'Services-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Not included',
                'display_amenities_categories_order' => 13,
                'icon_id' => 14,
                'icon_name' => 'Not included',
                'icon_css' => 'Not included-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Entertainment',
                'display_amenities_categories_order' => 14,
                'icon_id' => 4,
                'icon_name' => 'Entertainment',
                'icon_css' => 'Entertainment-ur',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($categories as $category) {
            AmenitiesCategory::create($category);
        }
    }
}
