<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketTypesTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $ticketTypes = [
            [
                'icon_id' => 10,
                'ticket_type_name' => 'Inquiries',
                'display_order' => 1,
                'status' => 1,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Property Viewings',
                'display_order' => 2,
                'status' => 1,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Offers/Negotiations',
                'display_order' => 3,
                'status' => 1,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'Closing Assistance',
                'display_order' => 4,
                'status' => 1,
            ],
            [
                'icon_id' => 8,
                'ticket_type_name' => 'Maintenance Requests',
                'display_order' => 5,
                'status' => 1,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'Feedback/Complaints',
                'display_order' => 6,
                'status' => 1,
            ],
            [
                'icon_id' => 7,
                'ticket_type_name' => 'General Support',
                'display_order' => 7,
                'status' => 1,
            ],
        ];

        foreach ($ticketTypes as $ticketType) {
            DB::table('ticket_types')->updateOrInsert(
                [
                    'ticket_type_name' =>
                        $ticketType['ticket_type_name'],
                ],
                [
                    'icon_id' => $this->getValidIconId(
                        $ticketType['icon_id']
                    ),
                    'display_order' => $ticketType['display_order'],
                    'status' => $ticketType['status'],
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