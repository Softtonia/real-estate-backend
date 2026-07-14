<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketStatusTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $statuses = [
            [
                'icon_id' => 7,
                'ticket_status_name' => 'New',
                'display_order' => 1,
            ],
            [
                'icon_id' => 10,
                'ticket_status_name' => 'In Progress',
                'display_order' => 2,
            ],
            [
                'icon_id' => 7,
                'ticket_status_name' => 'On Hold',
                'display_order' => 3,
            ],
            [
                'icon_id' => 8,
                'ticket_status_name' => 'Resolved',
                'display_order' => 4,
            ],
            [
                'icon_id' => 9,
                'ticket_status_name' => 'Closed',
                'display_order' => 5,
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('ticket_status')->updateOrInsert(
                [
                    'ticket_status_name' =>
                        $status['ticket_status_name'],
                ],
                [
                    'icon_id' => $this->getValidIconId(
                        $status['icon_id']
                    ),
                    'display_order' => $status['display_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function getValidIconId(?int $iconId): ?int
    {
        if (!$iconId) {
            return null;
        }

        return DB::table('media')
            ->where('id', $iconId)
            ->exists()
                ? $iconId
                : null;
    }
}