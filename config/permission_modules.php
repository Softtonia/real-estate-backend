<?php

return [

    'guard' => 'sanctum',

    'actions' => [
        'read',
        'create',
        'edit',
        'delete',
    ],

    'modules' => [

        'dashboard' => [
            'label' => 'Dashboard',
            'actions' => ['read'],
        ],

        'analytics' => [
            'label' => 'Analytics',
            'actions' => ['read'],
        ],

        'users' => [
            'label' => 'Users',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'roles' => [
            'label' => 'Roles',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'permissions' => [
            'label' => 'Permissions',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'groups' => [
            'label' => 'Groups',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'media' => [
            'label' => 'Media',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'pages' => [
            'label' => 'Pages',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'about_us' => [
            'label' => 'About Us',
            'actions' => ['read', 'edit'],
        ],

        'property_valuation' => [
            'label' => 'Property Valuation',
            'actions' => ['read', 'edit'],
        ],

        'services' => [
            'label' => 'Services',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'site_settings' => [
            'label' => 'Site Settings',
            'actions' => ['read', 'edit'],
        ],

        'mail_configs' => [
            'label' => 'Mail Configs',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'locations' => [
            'label' => 'Locations',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'help_categories' => [
            'label' => 'Help Categories',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'help_subcategories' => [
            'label' => 'Help Subcategories',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'help_childcategories' => [
            'label' => 'Help Child Categories',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'help_articles' => [
            'label' => 'Help Articles',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'client_reviews' => [
            'label' => 'Client Reviews',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'faq_categories' => [
            'label' => 'FAQ Categories',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'faqs' => [
            'label' => 'FAQs',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'top_features' => [
            'label' => 'Top Features',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'menus' => [
            'label' => 'Menus',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'tickets' => [
            'label' => 'Tickets',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'ticket_statuses' => [
            'label' => 'Ticket Statuses',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'ticket_departments' => [
            'label' => 'Ticket Departments',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'ticket_priorities' => [
            'label' => 'Ticket Priorities',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'ticket_types' => [
            'label' => 'Ticket Types',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'leads' => [
            'label' => 'Leads',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'lead_types' => [
            'label' => 'Lead Types',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'contact_us_leads' => [
            'label' => 'Contact Us Leads',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'business_enquiries' => [
            'label' => 'Business Enquiries',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'api_clients' => [
            'label' => 'API Clients',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'application_passwords' => [
            'label' => 'Application Passwords',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'blocked_api_ips' => [
            'label' => 'Blocked API IPs',
            'actions' => ['read', 'create', 'delete'],
        ],

        'api_auth_failures' => [
            'label' => 'API Auth Failures',
            'actions' => ['read'],
        ],

        'ip_logs' => [
            'label' => 'IP Logs',
            'actions' => ['read', 'edit'],
        ],

        'templates' => [
            'label' => 'Templates',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'template_builder' => [
            'label' => 'Template Builder',
            'actions' => ['read', 'edit'],
        ],

        'template_conditions' => [
            'label' => 'Template Conditions',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'template_revisions' => [
            'label' => 'Template Revisions',
            'actions' => ['read', 'edit'],
        ],

        'post_types' => [
            'label' => 'Post Types',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'dynamic_posts' => [
            'label' => 'Dynamic Posts',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'taxonomies' => [
            'label' => 'Taxonomies',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'taxonomy_terms' => [
            'label' => 'Taxonomy Terms',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'post_taxonomy_terms' => [
            'label' => 'Post Taxonomy Terms',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'custom_fields' => [
            'label' => 'Custom Fields',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'page_builder' => [
            'label' => 'Page Builder',
            'actions' => ['read'],
        ],

        'keywords' => [
            'label' => 'Keywords',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'subscribers' => [
            'label' => 'Subscribers',
            'actions' => ['read', 'create', 'delete'],
        ],
        'kyc_requests' => [
            'label' => 'KYC Requests',
            'actions' => ['read', 'create', 'edit', 'delete', 'approve', 'reject'],
        ],

        'kyc_settings' => [
            'label' => 'KYC Settings',
            'actions' => ['read', 'edit'],
        ],
        'property_verifications' => [
            'label' => 'Property Verifications',
            'actions' => ['read', 'assign', 'review', 'approve', 'reject'],
        ],
        'membership_categories' => [
            'label' => 'Membership Categories',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_plans' => [
            'label' => 'Membership Plans',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_features' => [
            'label' => 'Membership Features',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_plan_rules' => [
            'label' => 'Membership Plan Rules',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_orders' => [
            'label' => 'Membership Orders',
            'actions' => ['read', 'edit'],
        ],

        'membership_payments' => [
            'label' => 'Membership Payments',
            'actions' => ['read', 'refund'],
        ],

        'membership_coupons' => [
            'label' => 'Membership Coupons',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_users' => [
            'label' => 'User Memberships',
            'actions' => ['read', 'create', 'edit', 'cancel', 'manual_activate'],
        ],

        'membership_credits' => [
            'label' => 'Membership Credits',
            'actions' => ['read', 'adjust'],
        ],

        'membership_addons' => [
            'label' => 'Membership Add-ons',
            'actions' => ['read', 'create', 'edit', 'delete'],
        ],

        'membership_invoices' => [
            'label' => 'Membership Invoices',
            'actions' => ['read', 'download'],
        ],

        'membership_reports' => [
            'label' => 'Membership Reports',
            'actions' => ['read', 'export'],
        ],

        'membership_settings' => [
            'label' => 'Membership Settings',
            'actions' => ['read', 'edit'],
        ],

    ],

];
