<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketPrioritiesTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $priorities = [
            [
                'icon_id' => 4,
                'ticket_priority' => 'High',
                'display_order' => 1,
            ],
            [
                'icon_id' => 7,
                'ticket_priority' => 'Medium',
                'display_order' => 2,
            ],
            [
                'icon_id' => 8,
                'ticket_priority' => 'Low',
                'display_order' => 3,
            ],
        ];

        foreach ($priorities as $priority) {
            DB::table('ticket_priorities')->updateOrInsert(
                [
                    'ticket_priority' => $priority['ticket_priority'],
                ],
                [
                    'icon_id' => $this->getValidIconId(
                        $priority['icon_id']
                    ),
                    'display_order' => $priority['display_order'],
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
