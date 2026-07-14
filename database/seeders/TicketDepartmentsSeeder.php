<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketDepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $departments = [
            [
                'ticket_department_name' => 'Sales',
                'icon_id' => null,
                'display_order' => 1,
            ],
            [
                'ticket_department_name' => 'General',
                'icon_id' => null,
                'display_order' => 2,
            ],
            [
                'ticket_department_name' => 'Billing',
                'icon_id' => null,
                'display_order' => 3,
            ],
            [
                'ticket_department_name' => 'Technical Support',
                'icon_id' => null,
                'display_order' => 4,
            ],
            [
                'ticket_department_name' => 'Customer Support',
                'icon_id' => null,
                'display_order' => 5,
            ],
        ];

        foreach ($departments as $department) {
            DB::table('ticket_departments')->updateOrInsert(
                [
                    'ticket_department_name' =>
                        $department['ticket_department_name'],
                ],
                [
                    'icon_id' => $department['icon_id'],
                    'display_order' => $department['display_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}