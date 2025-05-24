<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // List of countries to insert
        $countries = [
            ['name' => 'United States'],
            ['name' => 'Canada'],
            ['name' => 'United Kingdom'],
            ['name' => 'Australia'],
            ['name' => 'India'],
            ['name' => 'Germany'],
            ['name' => 'France'],
            ['name' => 'Brazil'],
            ['name' => 'Mexico'],
            ['name' => 'Italy'],
            ['name' => 'Spain'],
            ['name' => 'Japan'],
            ['name' => 'China'],
            ['name' => 'South Korea'],
            ['name' => 'Russia'],
            ['name' => 'South Africa'],
            ['name' => 'New Zealand'],
            ['name' => 'Argentina'],
            ['name' => 'Egypt'],
            ['name' => 'Saudi Arabia'],
            ['name' => 'Turkey'],
            ['name' => 'Thailand'],
            ['name' => 'Indonesia'],
            ['name' => 'Vietnam'],
            ['name' => 'Philippines'],
            // Add more countries if needed
        ];

        // Insert countries into the 'countries' table, avoiding duplicates
        foreach ($countries as $country) {
            DB::table('countries')->updateOrInsert(
                ['name' => $country['name']],  // Check if the country name already exists
                ['name' => $country['name']]   // Insert the country if not found
            );
        }

        $this->command->info('Countries added/checked successfully.');
    }
}
