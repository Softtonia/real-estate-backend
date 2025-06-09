<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class TicketDepartmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['ticket_department_name' => 'Sales', 'icon_id' => null, 'display_order' => 1],
            ['ticket_department_name' => 'General', 'icon_id' => null, 'display_order' => 2],
            ['ticket_department_name' => 'Billing', 'icon_id' => null, 'display_order' => 3],
        ];

        foreach ($departments as $dept) {
            DB::table('ticket_departments')->insert([
                'ticket_department_name' => $dept['ticket_department_name'],
                'icon_id' => $dept['icon_id'],
                'display_order' => $dept['display_order'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
