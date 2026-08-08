<?php

return [
    'types' => [
        'general' => [
            'label' => 'General',
            'screens' => [
                'home' => [
                    'label' => 'Home',
                    'required_fields' => [],
                ],
                'notifications' => [
                    'label' => 'Notifications',
                    'required_fields' => [],
                ],
            ],
        ],

        'property' => [
            'label' => 'Property',
            'screens' => [
                'property_detail' => [
                    'label' => 'Property Detail',
                    'required_fields' => ['property_id'],
                ],
                'my_properties' => [
                    'label' => 'My Properties',
                    'required_fields' => [],
                ],
                'property_search' => [
                    'label' => 'Property Search',
                    'required_fields' => [],
                ],
            ],
        ],

        'kyc' => [
            'label' => 'KYC',
            'screens' => [
                'kyc_status' => [
                    'label' => 'KYC Status',
                    'required_fields' => [],
                ],
                'profile' => [
                    'label' => 'Profile',
                    'required_fields' => [],
                ],
            ],
        ],

        'membership' => [
            'label' => 'Membership',
            'screens' => [
                'membership_plans' => [
                    'label' => 'Membership Plans',
                    'required_fields' => [],
                ],
                'my_membership' => [
                    'label' => 'My Membership',
                    'required_fields' => [],
                ],
                'membership_payment' => [
                    'label' => 'Membership Payment',
                    'required_fields' => ['membership_id'],
                ],
            ],
        ],

        'payment' => [
            'label' => 'Payment',
            'screens' => [
                'payment_history' => [
                    'label' => 'Payment History',
                    'required_fields' => [],
                ],
                'payment_detail' => [
                    'label' => 'Payment Detail',
                    'required_fields' => ['order_id'],
                ],
            ],
        ],

        'lead' => [
            'label' => 'Lead / Enquiry',
            'screens' => [
                'my_leads' => [
                    'label' => 'My Leads',
                    'required_fields' => [],
                ],
                'lead_detail' => [
                    'label' => 'Lead Detail',
                    'required_fields' => ['lead_id'],
                ],
            ],
        ],

        'offer' => [
            'label' => 'Offer / Promotion',
            'screens' => [
                'membership_plans' => [
                    'label' => 'Membership Plans',
                    'required_fields' => [],
                ],
                'property_search' => [
                    'label' => 'Property Search',
                    'required_fields' => [],
                ],
                'external_url' => [
                    'label' => 'External URL',
                    'required_fields' => ['url'],
                ],
            ],
        ],

        'system' => [
            'label' => 'System Alert',
            'screens' => [
                'home' => [
                    'label' => 'Home',
                    'required_fields' => [],
                ],
                'notifications' => [
                    'label' => 'Notifications',
                    'required_fields' => [],
                ],
            ],
        ],

        'support' => [
            'label' => 'Support',
            'screens' => [
                'help_center' => [
                    'label' => 'Help Center',
                    'required_fields' => [],
                ],
                'support_ticket' => [
                    'label' => 'Support Ticket',
                    'required_fields' => ['ticket_id'],
                ],
            ],
        ],
    ],
];