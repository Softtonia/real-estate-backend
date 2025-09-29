<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $propertyTypes = [
            ['name' => 'Apartments', 'display_order' => '1', 'property_id' => 1],
            ['name' => 'Independent Houses', 'display_order' => '2', 'property_id' => 1],
            ['name' => 'Plots', 'display_order' => '3', 'property_id' => 1],
            ['name' => 'Townhouses', 'display_order' => '4', 'property_id' => 1],
            ['name' => 'Bungalows', 'display_order' => '5', 'property_id' => 1],
            ['name' => 'Office Spaces', 'display_order' => '6', 'property_id' => 2],
            ['name' => 'Retail Spaces', 'display_order' => '7', 'property_id' => 2],
            ['name' => 'Buildings', 'display_order' => '8', 'property_id' => 2],
            ['name' => 'Hospitality Properties', 'display_order' => '9', 'property_id' => 2],
            ['name' => 'Warehousing Spaces', 'display_order' => '10', 'property_id' => 2],
            ['name' => 'Institutional Properties', 'display_order' => '11', 'property_id' => 2],
            ['name' => 'Crop-Based Agricultural Land', 'display_order' => '12', 'property_id' => 3],
            ['name' => 'Horticulture Land', 'display_order' => '13', 'property_id' => 3],
            ['name' => 'Plantation Land', 'display_order' => '14', 'property_id' => 3],
            ['name' => 'Organic Farmland', 'display_order' => '15', 'property_id' => 3],
            ['name' => 'Fallow Land', 'display_order' => '16', 'property_id' => 3],
            ['name' => 'Pasture Land', 'display_order' => '17', 'property_id' => 3],
            ['name' => 'Agroforestry Land', 'display_order' => '18', 'property_id' => 3],
            ['name' => 'Industrial Land', 'display_order' => '19', 'property_id' => 4],
            ['name' => 'Industrial Sheds', 'display_order' => '20', 'property_id' => 4],
            ['name' => 'Manufacturing Units', 'display_order' => '21', 'property_id' => 4],
            ['name' => 'Logistics Facilities', 'display_order' => '22', 'property_id' => 4],
            ['name' => 'Industrial Parks', 'display_order' => '23', 'property_id' => 4],
            ['name' => 'Logistics Parks', 'display_order' => '24', 'property_id' => 4],
            ['name' => 'Hazardous Industry Zones', 'display_order' => '25', 'property_id' => 4],
            ['name' => 'Textile', 'display_order' => '26', 'property_id' => 4],
            ['name' => 'Pg', 'display_order' => '27', 'property_id' => 2],
        ];

        foreach ($propertyTypes as $type) {
            DB::table('property_types')->insert([
                'name' => $type['name'],
                'slug' => Str::slug($type['name']),
                'display_property_types_order' => $type['display_order'],
                'property_id' => $type['property_id'],
                'image' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
