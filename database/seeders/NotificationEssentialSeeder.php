<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

            DB::table('notification_topics')->updateOrInsert(
                ['slug' => $topic['slug']],
                $payload
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
                'name' => 'General Announcement',
                'slug' => 'general-announcement',
                'title' => 'New Update',
                'body' => 'A new update is available for you.',
                'type' => 'general',
                'screen' => 'home',
                'channel' => 'push_in_app',
                'description' => 'Use for normal announcement to users.',
                'data' => [
                    'type' => 'general',
                    'screen' => 'home',
                ],
            ],
            [
                'name' => 'Property Approved',
                'slug' => 'property-approved',
                'title' => 'Property Approved',
                'body' => 'Your property listing is now live.',
                'type' => 'property',
                'screen' => 'property_detail',
                'channel' => 'push_in_app',
                'description' => 'Send when property listing is approved.',
                'data' => [
                    'type' => 'property',
                    'screen' => 'property_detail',
                    'property_id' => null,
                ],
            ],
            [
                'name' => 'Property Rejected',
                'slug' => 'property-rejected',
                'title' => 'Property Rejected',
                'body' => 'Your property listing was rejected. Please check the reason and update it.',
                'type' => 'property',
                'screen' => 'my_properties',
                'channel' => 'push_in_app',
                'description' => 'Send when property listing is rejected.',
                'data' => [
                    'type' => 'property',
                    'screen' => 'my_properties',
                    'property_id' => null,
                ],
            ],
            [
                'name' => 'KYC Approved',
                'slug' => 'kyc-approved',
                'title' => 'KYC Approved',
                'body' => 'Your KYC verification has been approved.',
                'type' => 'kyc',
                'screen' => 'kyc_status',
                'channel' => 'push_in_app',
                'description' => 'Send when user KYC is approved.',
                'data' => [
                    'type' => 'kyc',
                    'screen' => 'kyc_status',
                ],
            ],
            [
                'name' => 'KYC Rejected',
                'slug' => 'kyc-rejected',
                'title' => 'KYC Rejected',
                'body' => 'Your KYC was rejected. Please update your documents.',
                'type' => 'kyc',
                'screen' => 'kyc_status',
                'channel' => 'push_in_app',
                'description' => 'Send when user KYC is rejected.',
                'data' => [
                    'type' => 'kyc',
                    'screen' => 'kyc_status',
                ],
            ],
            [
                'name' => 'Membership Expiring Soon',
                'slug' => 'membership-expiring-soon',
                'title' => 'Membership Expiring Soon',
                'body' => 'Your membership will expire soon. Renew now to continue your benefits.',
                'type' => 'membership',
                'screen' => 'my_membership',
                'channel' => 'push_in_app',
                'description' => 'Send before membership expiry.',
                'data' => [
                    'type' => 'membership',
                    'screen' => 'my_membership',
                    'membership_id' => null,
                ],
            ],
            [
                'name' => 'Membership Activated',
                'slug' => 'membership-activated',
                'title' => 'Membership Activated',
                'body' => 'Your membership has been activated successfully.',
                'type' => 'membership',
                'screen' => 'my_membership',
                'channel' => 'push_in_app',
                'description' => 'Send after successful membership activation.',
                'data' => [
                    'type' => 'membership',
                    'screen' => 'my_membership',
                    'membership_id' => null,
                ],
            ],
            [
                'name' => 'Payment Successful',
                'slug' => 'payment-successful',
                'title' => 'Payment Successful',
                'body' => 'Your payment was successful.',
                'type' => 'payment',
                'screen' => 'payment_detail',
                'channel' => 'push_in_app',
                'description' => 'Send after successful payment.',
                'data' => [
                    'type' => 'payment',
                    'screen' => 'payment_detail',
                    'order_id' => null,
                ],
            ],
            [
                'name' => 'Payment Failed',
                'slug' => 'payment-failed',
                'title' => 'Payment Failed',
                'body' => 'Your payment could not be completed. Please try again.',
                'type' => 'payment',
                'screen' => 'payment_history',
                'channel' => 'push_in_app',
                'description' => 'Send when payment fails.',
                'data' => [
                    'type' => 'payment',
                    'screen' => 'payment_history',
                    'order_id' => null,
                ],
            ],
            [
                'name' => 'New Lead Received',
                'slug' => 'new-lead-received',
                'title' => 'New Lead Received',
                'body' => 'You have received a new property enquiry.',
                'type' => 'lead',
                'screen' => 'lead_detail',
                'channel' => 'push_in_app',
                'description' => 'Send when owner/business receives a new lead.',
                'data' => [
                    'type' => 'lead',
                    'screen' => 'lead_detail',
                    'lead_id' => null,
                ],
            ],
            [
                'name' => 'Special Offer',
                'slug' => 'special-offer',
                'title' => 'Special Offer',
                'body' => 'A special offer is available for you.',
                'type' => 'offer',
                'screen' => 'membership_plans',
                'channel' => 'push_in_app',
                'description' => 'Use for marketing offers and promotions.',
                'data' => [
                    'type' => 'offer',
                    'screen' => 'membership_plans',
                ],
            ],
            [
                'name' => 'System Alert',
                'slug' => 'system-alert',
                'title' => 'System Alert',
                'body' => 'Important system update from Holiplaces.',
                'type' => 'system',
                'screen' => 'notifications',
                'channel' => 'push_in_app',
                'description' => 'Use for system or maintenance alerts.',
                'data' => [
                    'type' => 'system',
                    'screen' => 'notifications',
                ],
            ],
            [
                'name' => 'Support Ticket Update',
                'slug' => 'support-ticket-update',
                'title' => 'Support Ticket Updated',
                'body' => 'Your support ticket has been updated.',
                'type' => 'support',
                'screen' => 'support_ticket',
                'channel' => 'push_in_app',
                'description' => 'Send when support ticket is updated.',
                'data' => [
                    'type' => 'support',
                    'screen' => 'support_ticket',
                    'ticket_id' => null,
                ],
            ],
        ];

        foreach ($templates as $template) {
            $payload = $this->filterColumns('notification_templates', [
                'name' => $template['name'],
                'slug' => $template['slug'],
                'title' => $template['title'],
                'body' => $template['body'],
                'description' => $template['description'],
                'type' => $template['type'],
                'screen' => $template['screen'],
                'channel' => $template['channel'],
                'data' => json_encode($template['data']),
                'payload' => json_encode($template['data']),
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('notification_templates')->updateOrInsert(
                ['slug' => $template['slug']],
                $payload
            );
        }
    }

    private function filterColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $column) => Schema::hasColumn($table, $column))
            ->all();
    }
}