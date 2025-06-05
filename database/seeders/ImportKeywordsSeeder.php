<?php

namespace Database\Seeders;

use App\Models\ImportKeyword;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportKeywordsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $states = [
            'Andhra Pradesh',
            'Arunachal Pradesh',
            'Assam',
            'Bihar',
            'Chhattisgarh',
            'Goa',
            'Gujarat',
            'Haryana',
            'Himachal Pradesh',
            'Jharkhand',
            'Karnataka',
            'Kerala',
            'Madhya Pradesh',
            'Maharashtra',
            'Manipur',
            'Meghalaya',
            'Mizoram',
            'Nagaland',
            'Odisha',
            'Punjab',
            'Rajasthan',
            'Sikkim',
            'Tamil Nadu',
            'Telangana',
            'Tripura',
            'Uttar Pradesh',
            'Uttarakhand',
            'West Bengal',
            'Delhi',
            'Puducherry',
            'Jammu and Kashmir',
            'Ladakh','Chandigarh','Andaman and Nicobar Islands','Dadra and Nagar Haveli and Daman and Diu','Lakshadweep'
        ];

        // 3 keyword variants → 108 rows total
        $variants = [
            'Property' => 'property_keyword',
            'Project' => 'project_keyword',
            'Developer' => 'developer_keyword',
        ];

        foreach ($states as $state) {
            foreach ($variants as $prefix => $type) {

                $keyword = "{$prefix} in {$state}";
                $slug = Str::slug($keyword);

                ImportKeyword::updateOrCreate(
                    ['slug' => $slug],                 // unique key
                    [
                        'keyword_name' => $keyword,
                        'keyword_type' => $type
                    ]
                );
            }
        }
    }
}
