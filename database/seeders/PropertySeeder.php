<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $properties = [
            [
                'name' => 'Residential',
                'slug' => Str::slug('Residential'),
                'display_properties_order' => '1',
                'property_image' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Commercial',
                'slug' => Str::slug('Commercial'),
                'display_properties_order' => '2',
                'property_image' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Agricultural',
                'slug' => Str::slug('Agricultural'),
                'display_properties_order' => '3',
                'property_image' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Industrial',
                'slug' => Str::slug('Industrial'),
                'display_properties_order' => '4',
                'property_image' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        DB::table('properties')->insert($properties);
    }
}
