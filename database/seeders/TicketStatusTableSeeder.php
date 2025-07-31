<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TicketStatusTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $data = [
            [

                'icon_id' => 7,
                'ticket_status_name' => 'New',
                'display_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [

                'icon_id' => 10,
                'ticket_status_name' => 'In Progress',
                'display_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [

                'icon_id' => 7,
                'ticket_status_name' => 'On Hold',
                'display_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [

                'icon_id' => 8,
                'ticket_status_name' => 'Resolved',
                'display_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [

                'icon_id' => 9,
                'ticket_status_name' => 'Closed',
                'display_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],

        ];

        DB::table('ticket_status')->insert($data);
    }
}
