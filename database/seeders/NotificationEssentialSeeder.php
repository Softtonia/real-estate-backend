<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationEssentialSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTopics();
        $this->seedTemplates();
    }

    private function seedTopics(): void
    {
        if (! Schema::hasTable('notification_topics')) {
            return;
        }

        $topics = [
            [
                'name' => 'General Updates',
                'slug' => 'general-updates',
                'description' => 'General platform announcements and updates.',
            ],
            [
                'name' => 'Property Alerts',
                'slug' => 'property-alerts',
                'description' => 'Property approval, rejection, listing and search alerts.',
            ],
            [
                'name' => 'KYC Updates',
                'slug' => 'kyc-updates',
                'description' => 'KYC approval, rejection and verification updates.',
            ],
            [
                'name' => 'Membership Updates',
                'slug' => 'membership-updates',
                'description' => 'Membership purchase, expiry, renewal and credit updates.',
            ],
            [
                'name' => 'Payment Updates',
                'slug' => 'payment-updates',
                'description' => 'Payment success, failed, refund and invoice updates.',
            ],
            [
                'name' => 'Lead Alerts',
                'slug' => 'lead-alerts',
                'description' => 'New enquiry and lead notifications.',
            ],
            [
                'name' => 'Offers And Promotions',
                'slug' => 'offers-promotions',
                'description' => 'Marketing offers, discounts and promotions.',
            ],
            [
                'name' => 'System Alerts',
                'slug' => 'system-alerts',
                'description' => 'Maintenance, security and system level alerts.',
            ],
            [
                'name' => 'Support Updates',
                'slug' => 'support-updates',
                'description' => 'Support ticket and help center updates.',
            ],
        ];

        foreach ($topics as $topic) {
            $payload = $this->filterColumns('notification_topics', [
                'name' => $topic['name'],
                'slug' => $topic['slug'],
                'description' => $topic['description'],
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->updateOrInsertByExistingColumn(
                table: 'notification_topics',
                identityCandidates: [
                    'slug' => $topic['slug'],
                    'name' => $topic['name'],
                ],
                payload: $payload
            );
        }
    }

    private function seedTemplates(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        $templates = [
            [
                'template_key' => 'general_announcement',
                'title' => 'New Update',
                'body' => 'A new update is available for you.',
                'channel' => 'push',
                'type' => 'general',
                'screen' => 'home',
                'data' => [
                    'type' => 'general',
                    'screen' => 'home',
                ],
            ],
            [
                'template_key' => 'property_approved',
                'title' => 'Property Approved',
                'body' => 'Your property listing is now live.',
                'channel' => 'push',
                'type' => 'property',
                'screen' => 'property_detail',
                'data' => [
                    'type' => 'property',
                    'screen' => 'property_detail',
                    'property_id' => null,
                ],
            ],
            [
                'template_key' => 'property_rejected',
                'title' => 'Property Rejected',
                'body' => 'Your property listing was rejected. Please check the reason and update it.',
                'channel' => 'push',
                'type' => 'property',
                'screen' => 'my_properties',
                'data' => [
                    'type' => 'property',
                    'screen' => 'my_properties',
                    'property_id' => null,
                ],
            ],
            [
                'template_key' => 'kyc_approved',
                'title' => 'KYC Approved',
                'body' => 'Your KYC verification has been approved.',
                'channel' => 'push',
                'type' => 'kyc',
                'screen' => 'kyc_status',
                'data' => [
                    'type' => 'kyc',
                    'screen' => 'kyc_status',
                ],
            ],
            [
                'template_key' => 'kyc_rejected',
                'title' => 'KYC Rejected',
                'body' => 'Your KYC was rejected. Please update your documents.',
                'channel' => 'push',
                'type' => 'kyc',
                'screen' => 'kyc_status',
                'data' => [
                    'type' => 'kyc',
                    'screen' => 'kyc_status',
                ],
            ],
            [
                'template_key' => 'membership_activated',
                'title' => 'Membership Activated',
                'body' => 'Your membership has been activated successfully.',
                'channel' => 'push',
                'type' => 'membership',
                'screen' => 'my_membership',
                'data' => [
                    'type' => 'membership',
                    'screen' => 'my_membership',
                    'membership_id' => null,
                ],
            ],
            [
                'template_key' => 'membership_expiring_soon',
                'title' => 'Membership Expiring Soon',
                'body' => 'Your membership will expire soon. Renew now to continue your benefits.',
                'channel' => 'push',
                'type' => 'membership',
                'screen' => 'my_membership',
                'data' => [
                    'type' => 'membership',
                    'screen' => 'my_membership',
                    'membership_id' => null,
                ],
            ],
            [
                'template_key' => 'payment_successful',
                'title' => 'Payment Successful',
                'body' => 'Your payment was successful.',
                'channel' => 'push',
                'type' => 'payment',
                'screen' => 'payment_detail',
                'data' => [
                    'type' => 'payment',
                    'screen' => 'payment_detail',
                    'order_id' => null,
                ],
            ],
            [
                'template_key' => 'payment_failed',
                'title' => 'Payment Failed',
                'body' => 'Your payment could not be completed. Please try again.',
                'channel' => 'push',
                'type' => 'payment',
                'screen' => 'payment_history',
                'data' => [
                    'type' => 'payment',
                    'screen' => 'payment_history',
                    'order_id' => null,
                ],
            ],
            [
                'template_key' => 'new_lead_received',
                'title' => 'New Lead Received',
                'body' => 'You have received a new property enquiry.',
                'channel' => 'push',
                'type' => 'lead',
                'screen' => 'lead_detail',
                'data' => [
                    'type' => 'lead',
                    'screen' => 'lead_detail',
                    'lead_id' => null,
                ],
            ],
            [
                'template_key' => 'special_offer',
                'title' => 'Special Offer',
                'body' => 'A special offer is available for you.',
                'channel' => 'push',
                'type' => 'offer',
                'screen' => 'membership_plans',
                'data' => [
                    'type' => 'offer',
                    'screen' => 'membership_plans',
                ],
            ],
            [
                'template_key' => 'system_alert',
                'title' => 'System Alert',
                'body' => 'Important system update from Holiplaces.',
                'channel' => 'push',
                'type' => 'system',
                'screen' => 'notifications',
                'data' => [
                    'type' => 'system',
                    'screen' => 'notifications',
                ],
            ],
            [
                'template_key' => 'support_ticket_update',
                'title' => 'Support Ticket Updated',
                'body' => 'Your support ticket has been updated.',
                'channel' => 'push',
                'type' => 'support',
                'screen' => 'support_ticket',
                'data' => [
                    'type' => 'support',
                    'screen' => 'support_ticket',
                    'ticket_id' => null,
                ],
            ],
        ];

        foreach ($templates as $template) {
            $jsonData = json_encode($template['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $payload = $this->filterColumns('notification_templates', [
                'template_key' => $template['template_key'],

                // These columns may or may not exist. filterColumns will keep only existing columns.
                'name' => ucwords(str_replace('_', ' ', $template['template_key'])),
                'slug' => str_replace('_', '-', $template['template_key']),
                'description' => ucwords(str_replace('_', ' ', $template['template_key'])),

                'title' => $template['title'],
                'body' => $template['body'],
                'channel' => $template['channel'],
                'type' => $template['type'],
                'screen' => $template['screen'],
                'data' => $jsonData,
                'payload' => $jsonData,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->updateOrInsertByExistingColumn(
                table: 'notification_templates',
                identityCandidates: [
                    'template_key' => $template['template_key'],
                    'slug' => str_replace('_', '-', $template['template_key']),
                    'name' => ucwords(str_replace('_', ' ', $template['template_key'])),
                ],
                payload: $payload
            );
        }
    }

    private function updateOrInsertByExistingColumn(
        string $table,
        array $identityCandidates,
        array $payload
    ): void {
        foreach ($identityCandidates as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                DB::table($table)->updateOrInsert(
                    [$column => $value],
                    $payload
                );

                return;
            }
        }
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $column) => Schema::hasColumn($table, $column))
            ->all();
    }
}