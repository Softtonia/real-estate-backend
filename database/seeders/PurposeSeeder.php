<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;


class PurposeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $purposes = [
            [
                'name' => 'Buy',
                'slug' => Str::slug('Buy'),
                'purpose_display_order' => '1',
                'icon' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sell',
                'slug' => Str::slug('Sell'),
                'purpose_display_order' => '2',
                'icon' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Rent',
                'slug' => Str::slug('Rent'),
                'purpose_display_order' => '3',
                'icon' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        DB::table('purposes')->insert($purposes);
    }
}
