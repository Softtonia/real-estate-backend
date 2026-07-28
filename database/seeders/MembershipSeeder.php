<?php

namespace Database\Seeders;

use App\Models\Membership\MembershipAddon;
use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanFeature;
use App\Models\Membership\MembershipPlanRoleRule;
use App\Models\Membership\MembershipSetting;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MembershipSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $categories = $this->seedCategories();
            $features = $this->seedFeatures();
            $plans = $this->seedPlans($categories);

            $this->syncPlanFeatures($plans, $features);
            $this->syncPlanRoleRules($plans);
            $this->seedAddons();
            $this->seedSettings();
        });
    }

    private function seedCategories(): array
    {
        $items = [
            [
                'name' => 'Free Plans',
                'slug' => 'free-plans',
                'description' => 'Default free membership plans.',
                'sort_order' => 1,
            ],
            [
                'name' => 'Owner Plans',
                'slug' => 'owner-plans',
                'description' => 'Plans for property owners and sellers.',
                'sort_order' => 2,
            ],
            [
                'name' => 'Agent Plans',
                'slug' => 'agent-plans',
                'description' => 'Plans for agents, brokers, and consultants.',
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise Plans',
                'slug' => 'enterprise-plans',
                'description' => 'Plans for builders, developers, companies, and agencies.',
                'sort_order' => 4,
            ],
            [
                'name' => 'Buyer Plans',
                'slug' => 'buyer-plans',
                'description' => 'Premium buyer membership plans.',
                'sort_order' => 5,
            ],
            [
                'name' => 'Tenant Plans',
                'slug' => 'tenant-plans',
                'description' => 'Premium tenant membership plans.',
                'sort_order' => 6,
            ],
        ];

        $categories = [];

        foreach ($items as $item) {
            $categories[$item['slug']] = MembershipCategory::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'status' => true,
                    'sort_order' => $item['sort_order'],
                ]
            );
        }

        return $categories;
    }

    private function seedFeatures(): array
    {
        $items = [
            ['name' => 'Listing Limit', 'slug' => 'listing_limit', 'feature_type' => 'limit', 'sort_order' => 1],
            ['name' => 'Featured Listing Limit', 'slug' => 'featured_listing_limit', 'feature_type' => 'limit', 'sort_order' => 2],
            ['name' => 'Boost Limit', 'slug' => 'boost_limit', 'feature_type' => 'limit', 'sort_order' => 3],
            ['name' => 'Lead View Limit', 'slug' => 'lead_view_limit', 'feature_type' => 'limit', 'sort_order' => 4],
            ['name' => 'Photo Limit', 'slug' => 'photo_limit', 'feature_type' => 'limit', 'sort_order' => 5],
            ['name' => 'Video Upload Limit', 'slug' => 'video_upload_limit', 'feature_type' => 'limit', 'sort_order' => 6],
            ['name' => 'Virtual Tour Limit', 'slug' => 'virtual_tour_limit', 'feature_type' => 'limit', 'sort_order' => 7],

            ['name' => 'Premium Badge', 'slug' => 'premium_badge', 'feature_type' => 'boolean', 'sort_order' => 20],
            ['name' => 'Verified Badge', 'slug' => 'verified_badge', 'feature_type' => 'boolean', 'sort_order' => 21],
            ['name' => 'Top Search Placement', 'slug' => 'top_search_placement', 'feature_type' => 'boolean', 'sort_order' => 22],
            ['name' => 'Homepage Recommendation', 'slug' => 'homepage_recommendation', 'feature_type' => 'boolean', 'sort_order' => 23],
            ['name' => 'WhatsApp Inquiry Button', 'slug' => 'whatsapp_inquiry', 'feature_type' => 'boolean', 'sort_order' => 24],
            ['name' => 'Basic Analytics', 'slug' => 'basic_analytics', 'feature_type' => 'boolean', 'sort_order' => 25],
            ['name' => 'Advanced Analytics', 'slug' => 'advanced_analytics', 'feature_type' => 'boolean', 'sort_order' => 26],
            ['name' => 'Lead Management CRM', 'slug' => 'lead_crm', 'feature_type' => 'boolean', 'sort_order' => 27],
            ['name' => 'Lead Export', 'slug' => 'lead_export', 'feature_type' => 'boolean', 'sort_order' => 28],
            ['name' => 'Call Tracking', 'slug' => 'call_tracking', 'feature_type' => 'boolean', 'sort_order' => 29],
            ['name' => 'Auto Refresh Days', 'slug' => 'auto_refresh_days', 'feature_type' => 'number', 'sort_order' => 30],

            ['name' => 'AI Property Description', 'slug' => 'ai_description', 'feature_type' => 'boolean', 'sort_order' => 40],
            ['name' => 'AI Listing Optimization', 'slug' => 'ai_listing_optimization', 'feature_type' => 'boolean', 'sort_order' => 41],
            ['name' => 'AI Price Suggestion', 'slug' => 'ai_price_suggestion', 'feature_type' => 'boolean', 'sort_order' => 42],
            ['name' => 'AI Lead Scoring', 'slug' => 'ai_lead_scoring', 'feature_type' => 'boolean', 'sort_order' => 43],

            ['name' => 'Social Media Sharing', 'slug' => 'social_media_sharing', 'feature_type' => 'boolean', 'sort_order' => 50],
            ['name' => 'WhatsApp Marketing', 'slug' => 'whatsapp_marketing', 'feature_type' => 'boolean', 'sort_order' => 51],
            ['name' => 'Custom Landing Page', 'slug' => 'custom_landing_page', 'feature_type' => 'boolean', 'sort_order' => 52],
            ['name' => 'Dedicated Manager', 'slug' => 'dedicated_manager', 'feature_type' => 'boolean', 'sort_order' => 53],
            ['name' => 'Priority Support', 'slug' => 'priority_support', 'feature_type' => 'boolean', 'sort_order' => 54],
            ['name' => 'Market Insights', 'slug' => 'market_insights', 'feature_type' => 'boolean', 'sort_order' => 55],
            ['name' => 'Site Visit Calendar', 'slug' => 'site_visit_calendar', 'feature_type' => 'boolean', 'sort_order' => 56],
            ['name' => 'Follow-up Reminders', 'slug' => 'followup_reminders', 'feature_type' => 'boolean', 'sort_order' => 57],

            ['name' => 'Team Member Limit', 'slug' => 'team_member_limit', 'feature_type' => 'limit', 'sort_order' => 70],
            ['name' => 'Bulk Property Upload', 'slug' => 'bulk_property_upload', 'feature_type' => 'boolean', 'sort_order' => 71],
            ['name' => 'API Access', 'slug' => 'api_access', 'feature_type' => 'boolean', 'sort_order' => 72],
            ['name' => 'CRM Integration', 'slug' => 'crm_integration', 'feature_type' => 'boolean', 'sort_order' => 73],
            ['name' => 'Lead Assignment', 'slug' => 'lead_assignment', 'feature_type' => 'boolean', 'sort_order' => 74],
            ['name' => 'Project Showcase', 'slug' => 'project_showcase', 'feature_type' => 'boolean', 'sort_order' => 75],
            ['name' => 'Builder Banner', 'slug' => 'builder_banner', 'feature_type' => 'boolean', 'sort_order' => 76],
            ['name' => 'White-label Microsite', 'slug' => 'white_label_microsite', 'feature_type' => 'boolean', 'sort_order' => 77],

            ['name' => 'Saved Property Limit', 'slug' => 'saved_property_limit', 'feature_type' => 'limit', 'sort_order' => 90],
            ['name' => 'Advanced Filters', 'slug' => 'advanced_filters', 'feature_type' => 'boolean', 'sort_order' => 91],
            ['name' => 'Instant Alerts', 'slug' => 'instant_alerts', 'feature_type' => 'boolean', 'sort_order' => 92],
            ['name' => 'AI Recommendations', 'slug' => 'ai_recommendations', 'feature_type' => 'boolean', 'sort_order' => 93],
            ['name' => 'Property Comparison', 'slug' => 'property_comparison', 'feature_type' => 'boolean', 'sort_order' => 94],
            ['name' => 'Loan Calculator', 'slug' => 'loan_calculator', 'feature_type' => 'boolean', 'sort_order' => 95],
            ['name' => 'Price Trend Reports', 'slug' => 'price_trend_reports', 'feature_type' => 'boolean', 'sort_order' => 96],

            ['name' => 'Rental Agreement Help', 'slug' => 'rental_agreement_help', 'feature_type' => 'boolean', 'sort_order' => 110],
            ['name' => 'Moving Checklist', 'slug' => 'moving_checklist', 'feature_type' => 'boolean', 'sort_order' => 111],
            ['name' => 'Rent Negotiation Help', 'slug' => 'rent_negotiation_help', 'feature_type' => 'boolean', 'sort_order' => 112],
            ['name' => 'Tenant Verification', 'slug' => 'tenant_verification', 'feature_type' => 'boolean', 'sort_order' => 113],
        ];

        $features = [];

        foreach ($items as $item) {
            $features[$item['slug']] = MembershipFeature::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'feature_type' => $item['feature_type'],
                    'status' => true,
                    'sort_order' => $item['sort_order'],
                ]
            );
        }

        return $features;
    }

    private function seedPlans(array $categories): array
    {
        $items = [
            [
                'category' => 'free-plans',
                'name' => 'Free',
                'slug' => 'free',
                'short_description' => 'Basic free membership for new users.',
                'price' => 0,
                'sale_price' => null,
                'duration' => 100,
                'duration_type' => 'years',
                'sort_order' => 1,
                'metadata' => ['is_lifetime' => true],
            ],
            [
                'category' => 'owner-plans',
                'name' => 'Silver',
                'slug' => 'silver',
                'short_description' => 'Starter premium plan for individual owners.',
                'price' => 999,
                'sale_price' => null,
                'duration' => 3,
                'duration_type' => 'months',
                'sort_order' => 2,
                'metadata' => ['recommended_for' => 'Individual Owners'],
            ],
            [
                'category' => 'owner-plans',
                'name' => 'Gold',
                'slug' => 'gold',
                'short_description' => 'Advanced plan for owners and small agents.',
                'price' => 2499,
                'sale_price' => null,
                'duration' => 6,
                'duration_type' => 'months',
                'sort_order' => 3,
                'is_popular' => true,
                'metadata' => ['recommended_for' => 'Owners and Small Agents'],
            ],
            [
                'category' => 'agent-plans',
                'name' => 'Platinum',
                'slug' => 'platinum',
                'short_description' => 'Professional plan for agents and consultants.',
                'price' => 4999,
                'sale_price' => null,
                'duration' => 12,
                'duration_type' => 'months',
                'sort_order' => 4,
                'metadata' => ['recommended_for' => 'Professional Agents'],
            ],
            [
                'category' => 'enterprise-plans',
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'short_description' => 'Enterprise plan for builders, developers, and agencies.',
                'price' => 14999,
                'sale_price' => null,
                'duration' => 12,
                'duration_type' => 'months',
                'sort_order' => 5,
                'metadata' => ['recommended_for' => 'Builders and Developers'],
            ],
            [
                'category' => 'buyer-plans',
                'name' => 'Buyer Plus',
                'slug' => 'buyer-plus',
                'short_description' => 'Premium buyer plan for serious property buyers.',
                'price' => 499,
                'sale_price' => null,
                'duration' => 3,
                'duration_type' => 'months',
                'sort_order' => 6,
                'metadata' => ['recommended_for' => 'Buyers'],
            ],
            [
                'category' => 'tenant-plans',
                'name' => 'Rent Plus',
                'slug' => 'rent-plus',
                'short_description' => 'Premium tenant plan for rental users.',
                'price' => 699,
                'sale_price' => null,
                'duration' => 3,
                'duration_type' => 'months',
                'sort_order' => 7,
                'metadata' => ['recommended_for' => 'Tenants'],
            ],
        ];

        $plans = [];

        foreach ($items as $item) {
            $category = $categories[$item['category']] ?? null;

            if (!$category) {
                continue;
            }

            $plans[$item['slug']] = MembershipPlan::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'category_id' => $category->id,
                    'name' => $item['name'],
                    'short_description' => $item['short_description'],
                    'description' => null,
                    'currency' => 'INR',
                    'price' => $item['price'],
                    'sale_price' => $item['sale_price'],
                    'duration' => $item['duration'],
                    'duration_type' => $item['duration_type'],
                    'trial_days' => 0,
                    'is_popular' => $item['is_popular'] ?? false,
                    'status' => true,
                    'sort_order' => $item['sort_order'],
                    'metadata' => $item['metadata'] ?? [],
                ]
            );
        }

        return $plans;
    }

    private function syncPlanFeatures(array $plans, array $features): void
    {
        $map = [
            'free' => [
                'listing_limit' => 2,
                'photo_limit' => 10,
                'basic_analytics' => 'yes',
            ],

            'silver' => [
                'listing_limit' => 10,
                'featured_listing_limit' => 1,
                'photo_limit' => 'unlimited',
                'premium_badge' => 'yes',
                'top_search_placement' => 'medium',
                'whatsapp_inquiry' => 'yes',
                'basic_analytics' => 'yes',
                'priority_support' => 'email',
            ],

            'gold' => [
                'listing_limit' => 50,
                'featured_listing_limit' => 5,
                'boost_limit' => 3,
                'lead_view_limit' => 100,
                'photo_limit' => 'unlimited',
                'video_upload_limit' => 10,
                'virtual_tour_limit' => 5,
                'verified_badge' => 'yes',
                'premium_badge' => 'yes',
                'homepage_recommendation' => 'yes',
                'auto_refresh_days' => 7,
                'whatsapp_inquiry' => 'yes',
                'advanced_analytics' => 'yes',
                'lead_crm' => 'yes',
                'lead_export' => 'yes',
                'call_tracking' => 'yes',
                'ai_description' => 'yes',
            ],

            'platinum' => [
                'listing_limit' => 'unlimited',
                'featured_listing_limit' => 20,
                'boost_limit' => 10,
                'lead_view_limit' => 'unlimited',
                'photo_limit' => 'unlimited',
                'video_upload_limit' => 'unlimited',
                'virtual_tour_limit' => 'unlimited',
                'verified_badge' => 'yes',
                'premium_badge' => 'yes',
                'top_search_placement' => 'high',
                'homepage_recommendation' => 'yes',
                'advanced_analytics' => 'yes',
                'lead_crm' => 'yes',
                'lead_export' => 'yes',
                'call_tracking' => 'yes',
                'ai_description' => 'yes',
                'ai_listing_optimization' => 'yes',
                'ai_price_suggestion' => 'yes',
                'ai_lead_scoring' => 'yes',
                'social_media_sharing' => 'yes',
                'whatsapp_marketing' => 'yes',
                'custom_landing_page' => 'yes',
                'dedicated_manager' => 'yes',
                'priority_support' => 'premium',
                'market_insights' => 'yes',
                'site_visit_calendar' => 'yes',
                'followup_reminders' => 'yes',
            ],

            'enterprise' => [
                'listing_limit' => 'unlimited',
                'featured_listing_limit' => 'unlimited',
                'boost_limit' => 'unlimited',
                'lead_view_limit' => 'unlimited',
                'photo_limit' => 'unlimited',
                'video_upload_limit' => 'unlimited',
                'virtual_tour_limit' => 'unlimited',
                'team_member_limit' => 'unlimited',
                'verified_badge' => 'yes',
                'premium_badge' => 'yes',
                'top_search_placement' => 'highest',
                'homepage_recommendation' => 'yes',
                'advanced_analytics' => 'yes',
                'lead_crm' => 'yes',
                'lead_export' => 'yes',
                'bulk_property_upload' => 'yes',
                'api_access' => 'yes',
                'crm_integration' => 'yes',
                'lead_assignment' => 'yes',
                'project_showcase' => 'yes',
                'builder_banner' => 'yes',
                'dedicated_manager' => 'yes',
                'priority_support' => 'enterprise',
                'white_label_microsite' => 'yes',
            ],

            'buyer-plus' => [
                'lead_view_limit' => 100,
                'saved_property_limit' => 'unlimited',
                'advanced_filters' => 'yes',
                'instant_alerts' => 'yes',
                'ai_recommendations' => 'yes',
                'property_comparison' => 'yes',
                'site_visit_calendar' => 'yes',
                'loan_calculator' => 'yes',
                'price_trend_reports' => 'yes',
            ],

            'rent-plus' => [
                'lead_view_limit' => 50,
                'saved_property_limit' => 'unlimited',
                'advanced_filters' => 'yes',
                'instant_alerts' => 'yes',
                'rental_agreement_help' => 'yes',
                'moving_checklist' => 'yes',
                'rent_negotiation_help' => 'yes',
                'tenant_verification' => 'yes',
            ],
        ];

        foreach ($map as $planSlug => $featureValues) {
            $plan = $plans[$planSlug] ?? null;

            if (!$plan) {
                continue;
            }

            foreach ($featureValues as $featureSlug => $value) {
                $feature = $features[$featureSlug] ?? null;

                if (!$feature) {
                    continue;
                }

                MembershipPlanFeature::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'feature_value' => (string) $value,
                        'is_unlimited' => $value === 'unlimited',
                        'metadata' => [],
                    ]
                );
            }
        }
    }

    private function syncPlanRoleRules(array $plans): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        $roleMap = [
            'free' => $this->nonAdminRoleIds(),

            'silver' => $this->roleIdsByKeywords([
                'owner',
                'property owner',
                'land owner',
                'seller',
            ]),

            'gold' => $this->roleIdsByKeywords([
                'owner',
                'property owner',
                'land owner',
                'seller',
                'agent',
                'broker',
                'consultant',
                'consultancy',
            ]),

            'platinum' => $this->roleIdsByKeywords([
                'agent',
                'broker',
                'consultant',
                'consultancy',
                'agency',
                'company',
            ]),

            'enterprise' => $this->roleIdsByKeywords([
                'builder',
                'developer',
                'company',
                'agency',
                'enterprise',
            ]),

            'buyer-plus' => $this->roleIdsByKeywords([
                'buyer',
                'customer',
                'user',
            ]),

            'rent-plus' => $this->roleIdsByKeywords([
                'tenant',
                'renter',
                'rent',
            ]),
        ];

        foreach ($roleMap as $planSlug => $roleIds) {
            $plan = $plans[$planSlug] ?? null;

            if (!$plan || empty($roleIds)) {
                continue;
            }

            foreach ($roleIds as $roleId) {
                MembershipPlanRoleRule::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'role_id' => $roleId,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function seedAddons(): void
    {
        $items = [
            [
                'name' => 'Property Boost - 7 Days',
                'slug' => 'property-boost-7-days',
                'addon_type' => 'boost',
                'price' => 199,
                'credit_type' => 'boost',
                'credit_quantity' => 1,
                'duration_days' => 7,
                'sort_order' => 1,
            ],
            [
                'name' => 'Featured Listing - 15 Days',
                'slug' => 'featured-listing-15-days',
                'addon_type' => 'featured_listing',
                'price' => 499,
                'credit_type' => 'featured_listing',
                'credit_quantity' => 1,
                'duration_days' => 15,
                'sort_order' => 2,
            ],
            [
                'name' => 'Homepage Banner',
                'slug' => 'homepage-banner',
                'addon_type' => 'homepage_banner',
                'price' => 999,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => 7,
                'sort_order' => 3,
            ],
            [
                'name' => 'Verified Property',
                'slug' => 'verified-property',
                'addon_type' => 'verified_property',
                'price' => 299,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 4,
            ],
            [
                'name' => 'Professional Photography',
                'slug' => 'professional-photography',
                'addon_type' => 'photography',
                'price' => 1499,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 5,
            ],
            [
                'name' => 'Drone Photography',
                'slug' => 'drone-photography',
                'addon_type' => 'drone_photography',
                'price' => 2999,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 6,
            ],
            [
                'name' => '360 Virtual Tour',
                'slug' => '360-virtual-tour',
                'addon_type' => 'virtual_tour',
                'price' => 2499,
                'credit_type' => 'virtual_tour',
                'credit_quantity' => 1,
                'duration_days' => null,
                'sort_order' => 7,
            ],
            [
                'name' => 'AI Listing Enhancement',
                'slug' => 'ai-listing-enhancement',
                'addon_type' => 'ai_enhancement',
                'price' => 199,
                'credit_type' => 'ai_description',
                'credit_quantity' => 1,
                'duration_days' => null,
                'sort_order' => 8,
            ],
            [
                'name' => 'Legal Verification',
                'slug' => 'legal-verification',
                'addon_type' => 'legal_verification',
                'price' => 1999,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 9,
            ],
            [
                'name' => 'Property Valuation',
                'slug' => 'property-valuation',
                'addon_type' => 'valuation',
                'price' => 999,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 10,
            ],
            [
                'name' => 'Social Media Promotion',
                'slug' => 'social-media-promotion',
                'addon_type' => 'social_media_promotion',
                'price' => 999,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => 7,
                'sort_order' => 11,
            ],
            [
                'name' => 'Email Marketing Campaign',
                'slug' => 'email-marketing-campaign',
                'addon_type' => 'email_campaign',
                'price' => 499,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 12,
            ],
            [
                'name' => 'WhatsApp Campaign',
                'slug' => 'whatsapp-campaign',
                'addon_type' => 'whatsapp_campaign',
                'price' => 499,
                'credit_type' => null,
                'credit_quantity' => null,
                'duration_days' => null,
                'sort_order' => 13,
            ],
        ];

        foreach ($items as $item) {
            MembershipAddon::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => null,
                    'addon_type' => $item['addon_type'],
                    'currency' => 'INR',
                    'price' => $item['price'],
                    'sale_price' => null,
                    'credit_type' => $item['credit_type'],
                    'credit_quantity' => $item['credit_quantity'],
                    'duration_days' => $item['duration_days'],
                    'status' => true,
                    'sort_order' => $item['sort_order'],
                    'metadata' => [],
                ]
            );
        }
    }

    private function seedSettings(): void
    {
        $items = [
            [
                'key' => 'currency',
                'value' => 'INR',
                'value_type' => 'string',
                'is_public' => true,
                'description' => 'Default membership currency.',
            ],
            [
                'key' => 'gst_percentage',
                'value' => '18',
                'value_type' => 'number',
                'is_public' => true,
                'description' => 'Default GST percentage for membership orders.',
            ],
            [
                'key' => 'invoice_prefix',
                'value' => 'HPM',
                'value_type' => 'string',
                'is_public' => false,
                'description' => 'Invoice number prefix.',
            ],
            [
                'key' => 'order_prefix',
                'value' => 'HPMORD',
                'value_type' => 'string',
                'is_public' => false,
                'description' => 'Membership order number prefix.',
            ],
            [
                'key' => 'addon_order_prefix',
                'value' => 'HPMADD',
                'value_type' => 'string',
                'is_public' => false,
                'description' => 'Membership add-on order number prefix.',
            ],
            [
                'key' => 'refund_prefix',
                'value' => 'HPMREF',
                'value_type' => 'string',
                'is_public' => false,
                'description' => 'Membership refund number prefix.',
            ],
            [
                'key' => 'auto_renew_enabled',
                'value' => 'false',
                'value_type' => 'boolean',
                'is_public' => true,
                'description' => 'Auto renewal status.',
            ],
            [
                'key' => 'grace_period_days',
                'value' => '0',
                'value_type' => 'number',
                'is_public' => false,
                'description' => 'Grace period after membership expiry.',
            ],
            [
                'key' => 'renewal_reminder_days',
                'value' => json_encode([7, 3, 1]),
                'value_type' => 'json',
                'is_public' => false,
                'description' => 'Days before expiry to send renewal reminder.',
            ],
            [
                'key' => 'razorpay_enabled',
                'value' => 'true',
                'value_type' => 'boolean',
                'is_public' => false,
                'description' => 'Razorpay payment gateway enabled.',
            ],
        ];

        foreach ($items as $item) {
            MembershipSetting::query()->updateOrCreate(
                ['key' => $item['key']],
                [
                    'value' => $item['value'],
                    'value_type' => $item['value_type'],
                    'is_public' => $item['is_public'],
                    'description' => $item['description'],
                ]
            );
        }
    }

    private function nonAdminRoleIds(): array
    {
        $column = $this->roleNameColumn();

        if (!$column) {
            return [];
        }

        return Role::query()
            ->select(['id', $column])
            ->get()
            ->filter(function ($role) use ($column) {
                $name = $this->normalizeRoleName((string) $role->{$column});

                return !in_array($name, [
                    'admin',
                    'administrator',
                    'superadmin',
                    'superadministrator',
                    'super-admin',
                ], true);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function roleIdsByKeywords(array $keywords): array
    {
        $column = $this->roleNameColumn();

        if (!$column) {
            return [];
        }

        $normalizedKeywords = collect($keywords)
            ->map(fn ($keyword) => $this->normalizeRoleName((string) $keyword))
            ->filter()
            ->values();

        return Role::query()
            ->select(['id', $column])
            ->get()
            ->filter(function ($role) use ($column, $normalizedKeywords) {
                $roleName = $this->normalizeRoleName((string) $role->{$column});

                return $normalizedKeywords->contains(function ($keyword) use ($roleName) {
                    return Str::contains($roleName, $keyword);
                });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function roleNameColumn(): ?string
    {
        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column)) {
                return $column;
            }
        }

        return null;
    }

    private function normalizeRoleName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replace([' ', '_', '-'], '')
            ->toString();
    }
}