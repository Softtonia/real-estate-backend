<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $statuses = [
            [
                'property_type_id' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 19, 20, 21, 22, 23, 24, 25],
                'name' => 'Under Construction',
                'status_display_order' => 1,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
                'name' => 'Ready to Move-In',
                'status_display_order' => 2,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5],
                'name' => 'New Launch',
                'status_display_order' => 3,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
                'name' => 'Resale Property',
                'status_display_order' => 4,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5],
                'name' => 'Under Renovation',
                'status_display_order' => 5,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5],
                'name' => 'Possession Soon',
                'status_display_order' => 6,
            ],
            [
                'property_type_id' => [1, 2, 3, 4, 5],
                'name' => 'Delayed Projects',
                'status_display_order' => 7,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11, ],
                'name' => 'Pre-Launch',
                'status_display_order' => 8,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11,],
                'name' => 'Occupied',
                'status_display_order' => 9,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11,],
                'name' => 'Nearing Possession',
                'status_display_order' => 10,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11,],
                'name' => 'Furnished',
                'status_display_order' => 11,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11,],
                'name' => 'On Hold',
                'status_display_order' => 12,
            ],
            [
                'property_type_id' => [6, 7, 8, 9, 10, 11, 19, 20, 21, 22, 23, 24, 25],
                'name' => 'Leased Out',
                'status_display_order' => 13,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'In Use',
                'status_display_order' => 14,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Fallow Land',
                'status_display_order' => 15,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Multi-Crop Land',
                'status_display_order' => 16,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Single-Crop Land',
                'status_display_order' => 17,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Irrigated Land',
                'status_display_order' => 18,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Agricultural Land with Farmhouse',
                'status_display_order' => 19,
            ],
            [
                'property_type_id' => [12, 13, 14, 15, 16, 17, 18],
                'name' => 'Abandoned',
                'status_display_order' => 20,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25, 26],
                'name' => 'Ready to Use',
                'status_display_order' => 21,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25],
                'name' => 'Resale',
                'status_display_order' => 22,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25, 26],
                'name' => 'Vacant',
                'status_display_order' => 23,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25, 26],
                'name' => 'Shell Structure',
                'status_display_order' => 24,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25, 26],
                'name' => 'Renovated',
                'status_display_order' => 25,
            ],
            [
                'property_type_id' => [19, 20, 21, 22, 23, 24, 25, 26],
                'name' => 'Functional Unit',
                'status_display_order' => 26,
            ],

             [
                'property_type_id' => [27],
                'name' => 'Single Room',
                'status_display_order' => 27,
            ],
            [
                'property_type_id' => [27],
                'name' => 'Double Bed Room',
                'status_display_order' => 28,
            ],
            [
                'property_type_id' => [27],
                'name' => 'Triple Bed Room',
                'status_display_order' => 29,
            ],
        ];

        foreach ($statuses as $status) {
            Status::create([
                'property_type_id' => json_encode($status['property_type_id']),
                'name' => $status['name'],
                'slug' => Str::slug($status['name']),
                'status_display_order' => $status['status_display_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
