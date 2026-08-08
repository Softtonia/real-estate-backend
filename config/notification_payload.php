<?php

return [
    'types' => [
        'general' => [
            'label' => 'General',
            'screens' => ['home', 'notifications'],
            'required_fields' => [],
        ],

        'property' => [
            'label' => 'Property',
            'screens' => ['property_detail', 'my_properties', 'property_search'],
            'required_fields' => [
                'property_detail' => ['property_id'],
            ],
        ],

        'kyc' => [
            'label' => 'KYC',
            'screens' => ['kyc_status', 'profile'],
            'required_fields' => [],
        ],

        'membership' => [
            'label' => 'Membership',
            'screens' => ['membership_plans', 'my_membership', 'membership_payment'],
            'required_fields' => [
                'my_membership' => ['membership_id'],
            ],
        ],

        'payment' => [
            'label' => 'Payment',
            'screens' => ['payment_history', 'payment_detail', 'my_membership'],
            'required_fields' => [
                'payment_detail' => ['order_id'],
            ],
        ],

        'lead' => [
            'label' => 'Lead / Enquiry',
            'screens' => ['my_leads', 'lead_detail'],
            'required_fields' => [
                'lead_detail' => ['lead_id'],
            ],
        ],

        'offer' => [
            'label' => 'Offer / Promotion',
            'screens' => ['membership_plans', 'property_search', 'external_url'],
            'required_fields' => [
                'external_url' => ['url'],
            ],
        ],

        'system' => [
            'label' => 'System Alert',
            'screens' => ['home', 'notifications'],
            'required_fields' => [],
        ],

        'support' => [
            'label' => 'Support',
            'screens' => ['support_ticket', 'help_center'],
            'required_fields' => [
                'support_ticket' => ['ticket_id'],
            ],
        ],
    ],
];