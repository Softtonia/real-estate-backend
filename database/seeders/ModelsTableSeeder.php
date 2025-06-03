<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModelsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $models = [
            [
                'name' => 'purpose',
                'slug' => 'purpose',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ],
            [
                'name' => 'property',
                'slug' => 'property',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ],
            [
                'name' => 'property_type',
                'slug' => 'property-type',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ],
            [
                'name' => 'property_status',
                'slug' => 'property_status',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ],
            [
                'name' => 'amenities',
                'slug' => 'amenities',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ],
            [
                'name' => 'amenities_categories',
                'slug' => 'amenities-categories',
                'created_at' => now(),
                'updated_at' => now(),
                'status' => true
            ]
        ];

        DB::table('models')->insert($models);
    }
}
