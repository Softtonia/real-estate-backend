<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $filePath = storage_path('app/locationCsvFile/location1.csv'); // file storage/app/locations.csv me hona chahiye

        if (!file_exists($filePath)) {
            $this->command->error("CSV file not found at: $filePath");
            return;
        }

        $rows = array_map('str_getcsv', file($filePath));
        $header = array_map('strtolower', array_map('trim', $rows[0]));
        unset($rows[0]);

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                // Country check/create
                $country = Country::firstOrCreate(['name' => $data['country']]);

                // State check/create
                $state = State::firstOrCreate(
                    ['name' => $data['state'], 'country_id' => $country->id],
                    ['name' => $data['state'], 'country_id' => $country->id]
                );

                // City check/create
                City::firstOrCreate(
                    ['name' => $data['city'], 'state_id' => $state->id],
                    ['name' => $data['city'],
                    'state_id' => $state->id,
                    'is_popular' => isset($data['is_popular']) ? (bool) $data['is_popular'] : 0,
        'is_nearby'  => isset($data['is_nearby']) ? (bool) $data['is_nearby'] : 0,
                    ]
                );
            }

            DB::commit();
            $this->command->info("Locations seeded successfully from CSV.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("Error while seeding: " . $e->getMessage());
        }
    }
}
