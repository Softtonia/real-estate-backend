<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurposeSeeder extends Seeder
{
    public function run(): void
    {
        $purposes = [
            [
                'name' => 'Sell',
                'slug' => 'sell',
                'purpose_display_order' => 1,
                'icon' => null,
            ],
            [
                'name' => 'Rent',
                'slug' => 'rent',
                'purpose_display_order' => 2,
                'icon' => null,
            ],
        ];

        foreach ($purposes as $purpose) {
            $exists = DB::table('purposes')
                ->where('slug', $purpose['slug'])
                ->exists();

            if ($exists) {
                DB::table('purposes')
                    ->where('slug', $purpose['slug'])
                    ->update([
                        'name' => $purpose['name'],
                        'icon' => $purpose['icon'],
                        'purpose_display_order' => $purpose['purpose_display_order'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('purposes')->insert([
                    'name' => $purpose['name'],
                    'slug' => $purpose['slug'],
                    'icon' => $purpose['icon'],
                    'purpose_display_order' => $purpose['purpose_display_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}