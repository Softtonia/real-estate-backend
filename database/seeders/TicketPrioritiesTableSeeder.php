<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class TicketPrioritiesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $now = Carbon::now();
         $data = [
            [
                'icon_id' => 4,
                'ticket_priority' => 'High',
                'display_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 7,
                'ticket_priority' => 'Medium',
                'display_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'icon_id' => 8,
                'ticket_priority' => 'Low',
                'display_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('ticket_priorities')->insert($data);
    }
}
