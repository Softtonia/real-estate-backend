<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $states = [
            ['name' => 'California', 'country_id' => 1], // United States
            ['name' => 'Texas', 'country_id' => 1],
            ['name' => 'New York', 'country_id' => 1],
            ['name' => 'Florida', 'country_id' => 1],
            ['name' => 'Ontario', 'country_id' => 2], // Canada
            ['name' => 'Quebec', 'country_id' => 2],
            ['name' => 'British Columbia', 'country_id' => 2],
            ['name' => 'Alberta', 'country_id' => 2],
            ['name' => 'London', 'country_id' => 3], // United Kingdom
            ['name' => 'Scotland', 'country_id' => 3],
            ['name' => 'Wales', 'country_id' => 3],
            ['name' => 'New South Wales', 'country_id' => 4], // Australia
            ['name' => 'Queensland', 'country_id' => 4],
            ['name' => 'Victoria', 'country_id' => 4],
            ['name' => 'Andhra Pradesh', 'country_id' => 5],
            ['name' => 'Arunachal Pradesh', 'country_id' => 5],
            ['name' => 'Assam', 'country_id' => 5],
            ['name' => 'Bihar', 'country_id' => 5],
            ['name' => 'Chhattisgarh', 'country_id' => 5],
            ['name' => 'Goa', 'country_id' => 5],
            ['name' => 'Gujarat', 'country_id' => 5],
            ['name' => 'Haryana', 'country_id' => 5],
            ['name' => 'Himachal Pradesh', 'country_id' => 5],
            ['name' => 'Jharkhand', 'country_id' => 5],
            ['name' => 'Karnataka', 'country_id' => 5],
            ['name' => 'Kerala', 'country_id' => 5],
            ['name' => 'Madhya Pradesh', 'country_id' => 5],
            ['name' => 'Maharashtra', 'country_id' => 5],
            ['name' => 'Manipur', 'country_id' => 5],
            ['name' => 'Meghalaya', 'country_id' => 5],
            ['name' => 'Mizoram', 'country_id' => 5],
            ['name' => 'Nagaland', 'country_id' => 5],
            ['name' => 'Odisha', 'country_id' => 5],
            ['name' => 'Punjab', 'country_id' => 5],
            ['name' => 'Rajasthan', 'country_id' => 5],
            ['name' => 'Sikkim', 'country_id' => 5],
            ['name' => 'Tamil Nadu', 'country_id' => 5],
            ['name' => 'Telangana', 'country_id' => 5],
            ['name' => 'Tripura', 'country_id' => 5],
            ['name' => 'Uttar Pradesh', 'country_id' => 5],
            ['name' => 'Uttarakhand', 'country_id' => 5],
            ['name' => 'West Bengal', 'country_id' => 5],
            ['name' => 'Andaman and Nicobar Islands', 'country_id' => 5],
            ['name' => 'Chandigarh', 'country_id' => 5],
            ['name' => 'Dadra and Nagar Haveli and Daman and Diu', 'country_id' => 5],
            ['name' => 'Lakshadweep', 'country_id' => 5],
            ['name' => 'Delhi', 'country_id' => 5],
            ['name' => 'Puducherry', 'country_id' => 5],
            ['name' => 'Gujarat', 'country_id' => 5],
            ['name' => 'Haryana', 'country_id' => 5],
            ['name' => 'Sindh', 'country_id' => 6], // Pakistan
            ['name' => 'Punjab', 'country_id' => 6],
            ['name' => 'Khyber Pakhtunkhwa', 'country_id' => 6],
            ['name' => 'Balochistan', 'country_id' => 6],
            ['name' => 'New South Wales', 'country_id' => 7], // Australia
            ['name' => 'Victoria', 'country_id' => 7],
            ['name' => 'Western Cape', 'country_id' => 8], // South Africa
            ['name' => 'KwaZulu-Natal', 'country_id' => 8],
            ['name' => 'Gauteng', 'country_id' => 8],
            ['name' => 'Limpopo', 'country_id' => 8],
            // Add more states as needed
        ];

        // Insert or update the states in the 'states' table
        foreach ($states as $state) {
            DB::table('states')->updateOrInsert(
                ['name' => $state['name'], 'country_id' => $state['country_id']], // Check if the state already exists
                ['name' => $state['name'], 'country_id' => $state['country_id']]  // Update or insert the state
            );
        }

        $this->command->info('States added or updated successfully.');
    }
}
