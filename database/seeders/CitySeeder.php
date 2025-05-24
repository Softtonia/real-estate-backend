<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $cities = [
            // United States (Assuming state_id for California is 1)
            ['name' => 'Los Angeles', 'state_id' => 1], // California
            ['name' => 'San Francisco', 'state_id' => 1],
            ['name' => 'New York City', 'state_id' => 2], // New York
            ['name' => 'Buffalo', 'state_id' => 2],
            ['name' => 'Miami', 'state_id' => 3], // Florida
            ['name' => 'Orlando', 'state_id' => 3],

            // India (Assuming state_id for Maharashtra is 5)
            ['name' => 'Mumbai', 'state_id' => 5], // Maharashtra
            ['name' => 'Pune', 'state_id' => 5],
            ['name' => 'Amritsar', 'state_id' => 5], // Punjab
            ['name' => 'Ludhiana', 'state_id' => 5],
            ['name' => 'Chandigarh', 'state_id' => 5],
            ['name' => 'Patiala', 'state_id' => 5],
            ['name' => 'Jalandhar', 'state_id' => 5],
            ['name' => 'Bathinda', 'state_id' => 5],
            ['name' => 'Mohali', 'state_id' => 5],
            ['name' => 'Hoshiarpur', 'state_id' => 5],
            ['name' => 'Rupnagar', 'state_id' => 5],
            ['name' => 'Moga', 'state_id' => 5],
            ['name' => 'Firozpur', 'state_id' => 5],
            ['name' => 'Pathankot', 'state_id' => 5],
            ['name' => 'Sangrur', 'state_id' => 5],
            ['name' => 'Tarn Taran', 'state_id' => 5],
            ['name' => 'Gurdaspur', 'state_id' => 5],
            ['name' => 'Kapurthala', 'state_id' => 5],
            ['name' => 'Faridkot', 'state_id' => 5],
            ['name' => 'Fatehgarh Sahib', 'state_id' => 5],
            ['name' => 'Barnala', 'state_id' => 5],
            ['name' => 'Ropar', 'state_id' => 5],
            ['name' => 'Nawanshahr', 'state_id' => 5],
            ['name' => 'Zirakpur', 'state_id' => 5],
            ['name' => 'Derabassi', 'state_id' => 5],
            ['name' => 'Sri Muktsar Sahib', 'state_id' => 5],
            ['name' => 'Bangalore', 'state_id' => 6], // Karnataka
            ['name' => 'Mysore', 'state_id' => 6],
            ['name' => 'Chennai', 'state_id' => 7], // Tamil Nadu
            ['name' => 'Coimbatore', 'state_id' => 7],
            ['name' => 'Kolkata', 'state_id' => 8], // West Bengal
            ['name' => 'Siliguri', 'state_id' => 8],

            // Canada (Assuming state_id for Ontario is 9)
            ['name' => 'Toronto', 'state_id' => 9], // Ontario
            ['name' => 'Ottawa', 'state_id' => 9],
            ['name' => 'Montreal', 'state_id' => 10], // Quebec
            ['name' => 'Quebec City', 'state_id' => 10],

            // United Kingdom (Assuming state_id for England is 11)
            ['name' => 'London', 'state_id' => 11], // England
            ['name' => 'Manchester', 'state_id' => 11],
            ['name' => 'Edinburgh', 'state_id' => 12], // Scotland
            ['name' => 'Glasgow', 'state_id' => 12],

            // Australia (Assuming state_id for New South Wales is 13)
            ['name' => 'Sydney', 'state_id' => 13], // New South Wales
            ['name' => 'Newcastle', 'state_id' => 13],
            ['name' => 'Melbourne', 'state_id' => 14], // Victoria
            ['name' => 'Geelong', 'state_id' => 14],

            // Pakistan (Assuming state_id for Sindh is 15)
            ['name' => 'Karachi', 'state_id' => 15], // Sindh
            ['name' => 'Hyderabad', 'state_id' => 15],
            ['name' => 'Lahore', 'state_id' => 16], // Punjab
            ['name' => 'Faisalabad', 'state_id' => 16],
        ];

        // Insert or update cities to avoid duplicates
        foreach ($cities as $city) {
            DB::table('cities')->updateOrInsert(
                ['name' => $city['name'], 'state_id' => $city['state_id']], // Check if the city exists
                ['name' => $city['name'], 'state_id' => $city['state_id']]  // Insert or update data
            );
        }

        $this->command->info('Cities added or updated successfully.');
    }
}