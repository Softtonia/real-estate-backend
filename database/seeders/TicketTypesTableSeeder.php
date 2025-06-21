<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class TicketTypesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $now = Carbon::now();

        $data = [
            [
                'icon_id' => 10,
                'ticket_type_name' => 'Inquiries',
                'display_order' => 1,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Property Viewings',
                'display_order' => 2,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Offers/Negotiations',
                'display_order' => 3,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'Closing Assistance',
                'display_order' => 4,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Maintenance Requests',
                'display_order' => 5,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'Feedback/Complaints',
                'display_order' => 6,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'General Support',
                'display_order' => 7,
                'status' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('ticket_types')->insert($data);
    }

}
