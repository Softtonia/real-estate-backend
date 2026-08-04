<?php

namespace Database\Seeders;

use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanRoleRule;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MembershipExactPlansSeeder extends Seeder
{
    /**
     * This seeder is idempotent:
     * - categories are updated by slug
     * - features are updated by slug
     * - plans are updated by slug
     * - plan features are updated by plan_id + feature_id
     *
     * It seeds the exact plan structure from the recommended real-estate plans:
     * Owner: 3 plans for 90 days
     * Agent: 3 monthly + 3 annual
     * Consultancy: 3 monthly + 3 annual
     * Company: 3 monthly + 3 annual
     * Developer: 3 monthly + 3 annual
     */
    public function run(): void
    {
        DB::transaction(function () {
            $this->removeDuplicatePlanFeatures();

            $categories = $this->seedCategories();
            $features = $this->seedFeatures();

            foreach ($this->plans() as $planData) {
                $plan = $this->seedPlan($planData, $categories);

                $planFeatures = array_merge(
                    $this->commonPaidFeatures(),
                    $planData['features']
                );

                $this->syncPlanFeatures($plan, $features, $planFeatures);
                $this->syncPlanRoleRule($plan, $planData['role']);
            }

            $this->removeDuplicatePlanFeatures();
            $this->clearMembershipCaches();
        });
    }

    private function seedCategories(): array
    {
        $categories = [];

        foreach ($this->categories() as $index => $categoryData) {
            $payload = [
                'name' => $categoryData['name'],
                'description' => $categoryData['description'],
                'status' => true,
                'sort_order' => $index + 1,
            ];

            $category = MembershipCategory::query()->updateOrCreate(
                ['slug' => $categoryData['slug']],
                $payload
            );

            $categories[$categoryData['slug']] = $category;
        }

        return $categories;
    }

    private function seedFeatures(): array
    {
        $features = [];

        foreach ($this->features() as $slug => $featureData) {
            $feature = MembershipFeature::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $featureData['name'],
                    'description' => $featureData['description'] ?? null,
                    'feature_type' => $featureData['feature_type'],
                    'status' => true,
                    'sort_order' => $featureData['sort_order'] ?? 0,
                ]
            );

            $features[$slug] = $feature;
        }

        return $features;
    }

    private function seedPlan(array $planData, array $categories): MembershipPlan
    {
        $category = $categories[$planData['category_slug']];

        $payload = [
            'category_id' => $category->id,
            'name' => $planData['name'],
            'short_description' => $planData['short_description'],
            'description' => $planData['description'],
            'currency' => 'INR',
            'price' => $planData['price'],
            'sale_price' => null,
            'duration' => $planData['duration'],
            'duration_type' => $planData['duration_type'],
            'trial_days' => 0,
            'is_popular' => $planData['tier'] === 'standard',
            'status' => true,
            'sort_order' => $planData['sort_order'],
            'metadata' => [
                'user_type' => $planData['role'],
                'tier' => $planData['tier'],
                'billing_cycle' => $planData['billing_cycle'],
                'positioning' => $planData['positioning'],
                'prices_exclusive_of_gst' => true,
                'source' => 'MembershipExactPlansSeeder',
            ],
        ];

        return MembershipPlan::query()->updateOrCreate(
            ['slug' => $planData['slug']],
            $payload
        );
    }

    private function syncPlanFeatures(
        MembershipPlan $plan,
        array $features,
        array $planFeatures
    ): void {
        $sortOrder = 1;
        $syncedFeatureIds = [];

        foreach ($planFeatures as $slug => $rawValue) {
            if (! isset($features[$slug])) {
                continue;
            }

            $feature = $features[$slug];
            $value = $rawValue;
            $isUnlimited = false;

            if (is_array($rawValue)) {
                $value = $rawValue['value'] ?? null;
                $isUnlimited = (bool) ($rawValue['is_unlimited'] ?? false);
            }

            $syncedFeatureIds[] = (int) $feature->id;

            $payload = [];

            if (Schema::hasColumn('membership_plan_features', 'feature_value')) {
                $payload['feature_value'] = $this->featureValueForStorage($value);
            }

            if (Schema::hasColumn('membership_plan_features', 'value')) {
                $payload['value'] = $this->featureValueForStorage($value);
            }

            if (Schema::hasColumn('membership_plan_features', 'is_unlimited')) {
                $payload['is_unlimited'] = $isUnlimited;
            }

            if (Schema::hasColumn('membership_plan_features', 'status')) {
                $payload['status'] = true;
            }

            if (Schema::hasColumn('membership_plan_features', 'sort_order')) {
                $payload['sort_order'] = $sortOrder;
            }

            if (Schema::hasColumn('membership_plan_features', 'metadata')) {
                $payload['metadata'] = json_encode([
                    'seeded_by' => static::class,
                ]);
            }

            if (Schema::hasColumn('membership_plan_features', 'created_at')) {
                $payload['created_at'] = now();
            }

            if (Schema::hasColumn('membership_plan_features', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('membership_plan_features')->updateOrInsert(
                [
                    'plan_id' => $plan->id,
                    'feature_id' => $feature->id,
                ],
                $payload
            );

            $sortOrder++;
        }

        // Keep seeded plans exactly aligned with this file.
        if (! empty($syncedFeatureIds)) {
            DB::table('membership_plan_features')
                ->where('plan_id', $plan->id)
                ->whereNotIn('feature_id', $syncedFeatureIds)
                ->delete();
        }
    }

    private function syncPlanRoleRule(MembershipPlan $plan, string $roleSlug): void
    {
        if (! Schema::hasTable('membership_plan_role_rules') || ! Schema::hasTable('roles')) {
            return;
        }

        $roleId = $this->resolveRoleId($roleSlug);

        if (! $roleId) {
            return;
        }

        MembershipPlanRoleRule::query()->updateOrCreate(
            [
                'plan_id' => $plan->id,
                'role_id' => $roleId,
            ],
            [
                'is_active' => true,
            ]
        );

        MembershipPlanRoleRule::query()
            ->where('plan_id', $plan->id)
            ->where('role_id', '!=', $roleId)
            ->delete();
    }

    private function resolveRoleId(string $roleSlug): ?int
    {
        $roleSlug = Str::slug($roleSlug);

        $query = Role::query();

        $query->where(function ($q) use ($roleSlug) {
            if (Schema::hasColumn('roles', 'slug')) {
                $q->orWhere('slug', $roleSlug);
            }

            if (Schema::hasColumn('roles', 'name')) {
                $q->orWhereRaw('LOWER(name) = ?', [$roleSlug])
                    ->orWhereRaw('LOWER(REPLACE(name, " ", "-")) = ?', [$roleSlug]);
            }

            if (Schema::hasColumn('roles', 'role_name')) {
                $q->orWhereRaw('LOWER(role_name) = ?', [$roleSlug])
                    ->orWhereRaw('LOWER(REPLACE(role_name, " ", "-")) = ?', [$roleSlug]);
            }

            if (Schema::hasColumn('roles', 'title')) {
                $q->orWhereRaw('LOWER(title) = ?', [$roleSlug])
                    ->orWhereRaw('LOWER(REPLACE(title, " ", "-")) = ?', [$roleSlug]);
            }
        });

        $role = $query->first();

        return $role ? (int) $role->id : null;
    }

    private function featureValueForStorage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function removeDuplicatePlanFeatures(): void
    {
        if (! Schema::hasTable('membership_plan_features')) {
            return;
        }

        if (! Schema::hasColumn('membership_plan_features', 'id')) {
            return;
        }

        DB::statement("
            DELETE mpf1 FROM membership_plan_features mpf1
            INNER JOIN membership_plan_features mpf2
                ON mpf1.plan_id = mpf2.plan_id
                AND mpf1.feature_id = mpf2.feature_id
                AND mpf1.id > mpf2.id
        ");
    }

    private function clearMembershipCaches(): void
    {
        Cache::store('redis')->forget('membership:plans:active');
        Cache::store('redis')->forget('membership:admin:stats');
    }

    private function categories(): array
    {
        return [
            [
                'slug' => 'owner',
                'name' => 'Owner',
                'description' => 'Individual selling or renting their own property. Listing permission: property listings.',
            ],
            [
                'slug' => 'agent',
                'name' => 'Agent',
                'description' => 'Individual property broker. Listing permission: resale and rental property listings.',
            ],
            [
                'slug' => 'consultancy',
                'name' => 'Consultancy',
                'description' => 'Brokerage or consulting firm with a small team. Listing permission: property listings and agent management.',
            ],
            [
                'slug' => 'company',
                'name' => 'Company',
                'description' => 'Real-estate sales or marketing company handling projects. Listing permission: project listings and unit inventory.',
            ],
            [
                'slug' => 'developer',
                'name' => 'Developer',
                'description' => 'Builder or project owner. Listing permission: developer profile, projects and unit inventory.',
            ],
        ];
    }

    private function features(): array
    {
        $b = MembershipFeature::TYPE_BOOLEAN;
        $l = MembershipFeature::TYPE_LIMIT;
        $t = MembershipFeature::TYPE_TEXT;

        return [
            // Included in every paid plan.
            'secure_dashboard' => ['name' => 'Secure Dashboard', 'feature_type' => $b, 'sort_order' => 1],
            'direct_enquiry_notifications' => ['name' => 'Direct Enquiry Notifications', 'feature_type' => $b, 'sort_order' => 2],
            'whatsapp_email_alerts' => ['name' => 'WhatsApp and Email Alerts', 'feature_type' => $b, 'sort_order' => 3],
            'listing_editing_deactivation' => ['name' => 'Listing Editing and Deactivation', 'feature_type' => $b, 'sort_order' => 4],
            'listing_expiry_reminders' => ['name' => 'Listing Expiry Reminders', 'feature_type' => $b, 'sort_order' => 5],
            'spam_enquiry_reporting' => ['name' => 'Spam Enquiry Reporting', 'feature_type' => $b, 'sort_order' => 6],
            'favourite_view_statistics' => ['name' => 'Favourite and View Statistics', 'feature_type' => $b, 'sort_order' => 7],
            'mobile_responsive_profile_page' => ['name' => 'Mobile Responsive Profile Page', 'feature_type' => $b, 'sort_order' => 8],
            'property_sharing_link' => ['name' => 'Property Sharing Link', 'feature_type' => $b, 'sort_order' => 9],
            'customer_support_access' => ['name' => 'Customer Support Access', 'feature_type' => $b, 'sort_order' => 10],
            'invoice_download' => ['name' => 'Invoice Download', 'feature_type' => $b, 'sort_order' => 11],
            'secure_online_payments' => ['name' => 'Secure Online Payments', 'feature_type' => $b, 'sort_order' => 12],

            // Owner / agent / consultancy listing features.
            'active_property_listings' => ['name' => 'Active Property Listings', 'feature_type' => $l, 'sort_order' => 100],
            'property_photos' => ['name' => 'Property Photos', 'feature_type' => $l, 'sort_order' => 101],
            'property_videos' => ['name' => 'Property Videos', 'feature_type' => $t, 'sort_order' => 102],
            'listing_refreshes' => ['name' => 'Listing Refreshes', 'feature_type' => $l, 'sort_order' => 103],
            'featured_listing_credits' => ['name' => 'Featured Listing Credits', 'feature_type' => $l, 'sort_order' => 104],
            'listing_boost_credits' => ['name' => 'Listing Boost Credits', 'feature_type' => $l, 'sort_order' => 105],
            'buyer_contact_credits' => ['name' => 'Buyer Contact Credits', 'feature_type' => $l, 'sort_order' => 106],
            'search_visibility' => ['name' => 'Search Visibility', 'feature_type' => $t, 'sort_order' => 107],
            'direct_buyer_enquiries' => ['name' => 'Direct Buyer Enquiries', 'feature_type' => $t, 'sort_order' => 108],
            'owner_verification_badge' => ['name' => 'Owner Verification Badge', 'feature_type' => $t, 'sort_order' => 109],
            'listing_performance_report' => ['name' => 'Listing Performance Report', 'feature_type' => $t, 'sort_order' => 110],
            'social_sharing_tools' => ['name' => 'Social Sharing Tools', 'feature_type' => $b, 'sort_order' => 111],
            'property_valuation_report' => ['name' => 'Property Valuation Report', 'feature_type' => $t, 'sort_order' => 112],
            'relationship_manager' => ['name' => 'Relationship Manager', 'feature_type' => $t, 'sort_order' => 113],
            'support' => ['name' => 'Support', 'feature_type' => $t, 'sort_order' => 114],

            // Agent / consultancy lead and profile features.
            'team_members' => ['name' => 'Team Members', 'feature_type' => $l, 'sort_order' => 200],
            'operating_localities' => ['name' => 'Operating Localities', 'feature_type' => $l, 'sort_order' => 201],
            'agent_profile_page' => ['name' => 'Agent Profile Page', 'feature_type' => $t, 'sort_order' => 202],
            'logo_on_listing_cards' => ['name' => 'Logo on Listing Cards', 'feature_type' => $b, 'sort_order' => 203],
            'lead_management_dashboard' => ['name' => 'Lead Management Dashboard', 'feature_type' => $t, 'sort_order' => 204],
            'lead_management_system' => ['name' => 'Lead Management System', 'feature_type' => $t, 'sort_order' => 205],
            'lead_status_notes' => ['name' => 'Lead Status and Notes', 'feature_type' => $b, 'sort_order' => 206],
            'whatsapp_lead_notification' => ['name' => 'WhatsApp Lead Notification', 'feature_type' => $b, 'sort_order' => 207],
            'lead_assignment' => ['name' => 'Lead Assignment', 'feature_type' => $t, 'sort_order' => 208],
            'bulk_listing_upload' => ['name' => 'Bulk Listing Upload', 'feature_type' => $t, 'sort_order' => 209],
            'bulk_property_upload' => ['name' => 'Bulk Property Upload', 'feature_type' => $t, 'sort_order' => 210],
            'listing_analytics' => ['name' => 'Listing Analytics', 'feature_type' => $t, 'sort_order' => 211],
            'lead_source_reporting' => ['name' => 'Lead Source Reporting', 'feature_type' => $b, 'sort_order' => 212],
            'verified_agent_badge' => ['name' => 'Verified Agent Badge', 'feature_type' => $t, 'sort_order' => 213],
            'priority_search_placement' => ['name' => 'Priority Search Placement', 'feature_type' => $t, 'sort_order' => 214],
            'crm_integration' => ['name' => 'CRM Integration', 'feature_type' => $t, 'sort_order' => 215],
            'branch_locations' => ['name' => 'Branch Locations', 'feature_type' => $l, 'sort_order' => 216],
            'consultancy_profile' => ['name' => 'Consultancy Profile', 'feature_type' => $t, 'sort_order' => 217],
            'agent_profiles_under_company' => ['name' => 'Agent Profiles Under Company', 'feature_type' => $l, 'sort_order' => 218],
            'lead_assignment_to_agents' => ['name' => 'Lead Assignment to Agents', 'feature_type' => $b, 'sort_order' => 219],
            'follow_up_reminders' => ['name' => 'Follow-up Reminders', 'feature_type' => $b, 'sort_order' => 220],
            'lead_activity_history' => ['name' => 'Lead Activity History', 'feature_type' => $b, 'sort_order' => 221],
            'custom_lead_stages' => ['name' => 'Custom Lead Stages', 'feature_type' => $b, 'sort_order' => 222],
            'team_performance_reports' => ['name' => 'Team Performance Reports', 'feature_type' => $t, 'sort_order' => 223],
            'website_enquiry_integration' => ['name' => 'Website Enquiry Integration', 'feature_type' => $b, 'sort_order' => 224],
            'data_export' => ['name' => 'Data Export', 'feature_type' => $t, 'sort_order' => 225],

            // Company / developer project features.
            'active_projects' => ['name' => 'Active Projects', 'feature_type' => $l, 'sort_order' => 300],
            'unit_inventory' => ['name' => 'Unit Inventory', 'feature_type' => $l, 'sort_order' => 301],
            'project_locations' => ['name' => 'Project Locations', 'feature_type' => $l, 'sort_order' => 302],
            'project_boost_credits' => ['name' => 'Project Boost Credits', 'feature_type' => $l, 'sort_order' => 303],
            'featured_project_credits' => ['name' => 'Featured Project Credits', 'feature_type' => $l, 'sort_order' => 304],
            'project_microsites' => ['name' => 'Project Microsites', 'feature_type' => $t, 'sort_order' => 305],
            'company_profile_page' => ['name' => 'Company Profile Page', 'feature_type' => $t, 'sort_order' => 306],
            'logo_on_project_cards' => ['name' => 'Logo on Project Cards', 'feature_type' => $b, 'sort_order' => 307],
            'brochure_uploads' => ['name' => 'Brochure Uploads', 'feature_type' => $b, 'sort_order' => 308],
            'floor_plan_uploads' => ['name' => 'Floor-plan Uploads', 'feature_type' => $t, 'sort_order' => 309],
            'project_videos' => ['name' => 'Project Videos', 'feature_type' => $t, 'sort_order' => 310],
            'construction_updates' => ['name' => 'Construction Updates', 'feature_type' => $t, 'sort_order' => 311],
            'unit_availability_management' => ['name' => 'Unit Availability Management', 'feature_type' => $b, 'sort_order' => 312],
            'source_wise_lead_reports' => ['name' => 'Source-wise Lead Reports', 'feature_type' => $b, 'sort_order' => 313],
            'campaign_tracking' => ['name' => 'Campaign Tracking', 'feature_type' => $t, 'sort_order' => 314],
            'api_access' => ['name' => 'API Access', 'feature_type' => $b, 'sort_order' => 315],
            'meta_google_lead_integration' => ['name' => 'Meta/Google Lead Integration', 'feature_type' => $b, 'sort_order' => 316],

            'developer_profiles' => ['name' => 'Developer Profiles', 'feature_type' => $l, 'sort_order' => 400],
            'cities_covered' => ['name' => 'Cities Covered', 'feature_type' => $t, 'sort_order' => 401],
            'premium_search_placement' => ['name' => 'Premium Search Placement', 'feature_type' => $t, 'sort_order' => 402],
            'developer_profile_page' => ['name' => 'Developer Profile Page', 'feature_type' => $t, 'sort_order' => 403],
            'individual_project_microsites' => ['name' => 'Individual Project Microsites', 'feature_type' => $t, 'sort_order' => 404],
            'logo_brand_colours' => ['name' => 'Logo and Brand Colours', 'feature_type' => $b, 'sort_order' => 405],
            'brochures_floor_plans' => ['name' => 'Brochures and Floor Plans', 'feature_type' => $b, 'sort_order' => 406],
            'project_walkthrough_videos' => ['name' => 'Project Walkthrough Videos', 'feature_type' => $t, 'sort_order' => 407],
            'offers_payment_plans' => ['name' => 'Offers and Payment Plans', 'feature_type' => $b, 'sort_order' => 408],
            'tower_inventory_management' => ['name' => 'Tower and Inventory Management', 'feature_type' => $t, 'sort_order' => 409],
            'lead_distribution_rules' => ['name' => 'Lead Distribution Rules', 'feature_type' => $b, 'sort_order' => 410],
            'sales_team_reports' => ['name' => 'Sales-team Reports', 'feature_type' => $t, 'sort_order' => 411],
            'campaign_attribution' => ['name' => 'Campaign Attribution', 'feature_type' => $t, 'sort_order' => 412],
            'api_webhook_access' => ['name' => 'API and Webhook Access', 'feature_type' => $b, 'sort_order' => 413],
            'retargeting_campaigns' => ['name' => 'Retargeting Campaigns', 'feature_type' => $t, 'sort_order' => 414],
            'homepage_visibility' => ['name' => 'Homepage Visibility', 'feature_type' => $t, 'sort_order' => 415],
        ];
    }

    private function commonPaidFeatures(): array
    {
        return [
            'secure_dashboard' => true,
            'direct_enquiry_notifications' => true,
            'whatsapp_email_alerts' => true,
            'listing_editing_deactivation' => true,
            'listing_expiry_reminders' => true,
            'spam_enquiry_reporting' => true,
            'favourite_view_statistics' => true,
            'mobile_responsive_profile_page' => true,
            'property_sharing_link' => true,
            'customer_support_access' => true,
            'invoice_download' => true,
            'secure_online_payments' => true,
        ];
    }

    private function plans(): array
    {
        return array_merge(
            $this->ownerPlans(),
            $this->professionalPlans('agent', 'Agent', 'agent', [
                'monthly' => [1499, 3499, 6999],
                'annual' => [14990, 34990, 69990],
            ], [
                'basic' => [
                    'positioning' => 'For individual agents starting online.',
                    'features' => [
                        'active_property_listings' => 15,
                        'featured_listing_credits' => 2,
                        'listing_boost_credits' => 5,
                        'buyer_contact_credits' => 10,
                        'team_members' => 1,
                        'operating_localities' => 2,
                        'agent_profile_page' => 'Basic',
                        'logo_on_listing_cards' => false,
                        'lead_management_dashboard' => 'Basic',
                        'lead_status_notes' => true,
                        'whatsapp_lead_notification' => true,
                        'lead_assignment' => '—',
                        'bulk_listing_upload' => '—',
                        'listing_analytics' => 'Basic',
                        'lead_source_reporting' => false,
                        'verified_agent_badge' => 'After KYC/RERA',
                        'priority_search_placement' => '—',
                        'crm_integration' => '—',
                        'relationship_manager' => '—',
                        'support' => 'Email',
                    ],
                ],
                'standard' => [
                    'positioning' => 'For active agents managing regular inventory.',
                    'features' => [
                        'active_property_listings' => 50,
                        'featured_listing_credits' => 8,
                        'listing_boost_credits' => 20,
                        'buyer_contact_credits' => 50,
                        'team_members' => 3,
                        'operating_localities' => 5,
                        'agent_profile_page' => 'Branded',
                        'logo_on_listing_cards' => true,
                        'lead_management_dashboard' => 'Advanced',
                        'lead_status_notes' => true,
                        'whatsapp_lead_notification' => true,
                        'lead_assignment' => 'Yes',
                        'bulk_listing_upload' => 'CSV',
                        'listing_analytics' => 'Detailed',
                        'lead_source_reporting' => true,
                        'verified_agent_badge' => 'After KYC/RERA',
                        'priority_search_placement' => 'Limited',
                        'crm_integration' => '—',
                        'relationship_manager' => '—',
                        'support' => 'Chat and phone',
                    ],
                ],
                'pro' => [
                    'positioning' => 'For high-volume agents and growing teams.',
                    'features' => [
                        'active_property_listings' => 150,
                        'featured_listing_credits' => 25,
                        'listing_boost_credits' => 60,
                        'buyer_contact_credits' => 150,
                        'team_members' => 10,
                        'operating_localities' => 15,
                        'agent_profile_page' => 'Premium microsite',
                        'logo_on_listing_cards' => true,
                        'lead_management_dashboard' => 'Advanced',
                        'lead_status_notes' => true,
                        'whatsapp_lead_notification' => true,
                        'lead_assignment' => 'Yes',
                        'bulk_listing_upload' => 'CSV and API',
                        'listing_analytics' => 'Advanced',
                        'lead_source_reporting' => true,
                        'verified_agent_badge' => 'After KYC/RERA',
                        'priority_search_placement' => 'Highest',
                        'crm_integration' => 'Yes',
                        'relationship_manager' => 'Yes',
                        'support' => 'Priority support',
                    ],
                ],
            ]),
            $this->professionalPlans('consultancy', 'Consultancy', 'consultancy', [
                'monthly' => [2999, 6999, 12999],
                'annual' => [29990, 69990, 129990],
            ], [
                'basic' => [
                    'positioning' => 'For small property consultancies.',
                    'features' => [
                        'active_property_listings' => 30,
                        'featured_listing_credits' => 5,
                        'listing_boost_credits' => 10,
                        'buyer_contact_credits' => 25,
                        'team_members' => 2,
                        'branch_locations' => 1,
                        'operating_localities' => 5,
                        'consultancy_profile' => 'Basic',
                        'agent_profiles_under_company' => 2,
                        'lead_management_system' => 'Basic',
                        'lead_assignment_to_agents' => true,
                        'follow_up_reminders' => false,
                        'lead_activity_history' => false,
                        'custom_lead_stages' => false,
                        'team_performance_reports' => '—',
                        'bulk_property_upload' => 'CSV',
                        'crm_integration' => '—',
                        'website_enquiry_integration' => false,
                        'data_export' => 'Basic CSV',
                        'relationship_manager' => '—',
                        'support' => 'Email',
                    ],
                ],
                'standard' => [
                    'positioning' => 'For established local brokerage teams.',
                    'features' => [
                        'active_property_listings' => 100,
                        'featured_listing_credits' => 15,
                        'listing_boost_credits' => 40,
                        'buyer_contact_credits' => 100,
                        'team_members' => 5,
                        'branch_locations' => 3,
                        'operating_localities' => 15,
                        'consultancy_profile' => 'Branded',
                        'agent_profiles_under_company' => 5,
                        'lead_management_system' => 'Advanced',
                        'lead_assignment_to_agents' => true,
                        'follow_up_reminders' => true,
                        'lead_activity_history' => true,
                        'custom_lead_stages' => true,
                        'team_performance_reports' => 'Basic',
                        'bulk_property_upload' => 'CSV',
                        'crm_integration' => 'Optional',
                        'website_enquiry_integration' => false,
                        'data_export' => 'Full CSV',
                        'relationship_manager' => 'Shared',
                        'support' => 'Phone',
                    ],
                ],
                'pro' => [
                    'positioning' => 'For multi-location and high-volume consultancies.',
                    'features' => [
                        'active_property_listings' => 300,
                        'featured_listing_credits' => 40,
                        'listing_boost_credits' => 100,
                        'buyer_contact_credits' => 300,
                        'team_members' => 15,
                        'branch_locations' => 10,
                        'operating_localities' => 50,
                        'consultancy_profile' => 'Premium microsite',
                        'agent_profiles_under_company' => 15,
                        'lead_management_system' => 'Advanced',
                        'lead_assignment_to_agents' => true,
                        'follow_up_reminders' => true,
                        'lead_activity_history' => true,
                        'custom_lead_stages' => true,
                        'team_performance_reports' => 'Advanced',
                        'bulk_property_upload' => 'CSV and API',
                        'crm_integration' => 'Included',
                        'website_enquiry_integration' => true,
                        'data_export' => 'CSV and API',
                        'relationship_manager' => 'Dedicated',
                        'support' => 'Priority support',
                    ],
                ],
            ]),
            $this->professionalPlans('company', 'Company', 'company', [
                'monthly' => [4999, 11999, 24999],
                'annual' => [49990, 119990, 249990],
            ], [
                'basic' => [
                    'positioning' => 'For companies handling a few local projects.',
                    'features' => [
                        'active_projects' => 3,
                        'unit_inventory' => 30,
                        'team_members' => 3,
                        'project_locations' => 2,
                        'project_boost_credits' => 2,
                        'featured_project_credits' => 1,
                        'project_microsites' => 'Basic',
                        'company_profile_page' => 'Yes',
                        'logo_on_project_cards' => true,
                        'brochure_uploads' => true,
                        'floor_plan_uploads' => '10/project',
                        'project_videos' => '1/project',
                        'construction_updates' => 'Basic',
                        'unit_availability_management' => true,
                        'lead_assignment' => 'Basic',
                        'source_wise_lead_reports' => false,
                        'campaign_tracking' => '—',
                        'crm_integration' => '—',
                        'api_access' => false,
                        'meta_google_lead_integration' => false,
                        'relationship_manager' => '—',
                        'support' => 'Email',
                    ],
                ],
                'standard' => [
                    'positioning' => 'For established real-estate marketing companies.',
                    'features' => [
                        'active_projects' => 10,
                        'unit_inventory' => 150,
                        'team_members' => 10,
                        'project_locations' => 8,
                        'project_boost_credits' => 8,
                        'featured_project_credits' => 4,
                        'project_microsites' => 'Branded',
                        'company_profile_page' => 'Branded',
                        'logo_on_project_cards' => true,
                        'brochure_uploads' => true,
                        'floor_plan_uploads' => '30/project',
                        'project_videos' => '5/project',
                        'construction_updates' => 'Yes',
                        'unit_availability_management' => true,
                        'lead_assignment' => 'Advanced',
                        'source_wise_lead_reports' => true,
                        'campaign_tracking' => 'Basic',
                        'crm_integration' => 'Optional',
                        'api_access' => false,
                        'meta_google_lead_integration' => false,
                        'relationship_manager' => 'Shared',
                        'support' => 'Phone',
                    ],
                ],
                'pro' => [
                    'positioning' => 'For multi-project sales organisations.',
                    'features' => [
                        'active_projects' => 30,
                        'unit_inventory' => 500,
                        'team_members' => 30,
                        'project_locations' => 20,
                        'project_boost_credits' => 20,
                        'featured_project_credits' => 10,
                        'project_microsites' => 'Premium',
                        'company_profile_page' => 'Premium branded',
                        'logo_on_project_cards' => true,
                        'brochure_uploads' => true,
                        'floor_plan_uploads' => ['value' => 'Unlimited fair use', 'is_unlimited' => true],
                        'project_videos' => '10/project',
                        'construction_updates' => 'Yes',
                        'unit_availability_management' => true,
                        'lead_assignment' => 'Advanced',
                        'source_wise_lead_reports' => true,
                        'campaign_tracking' => 'Advanced',
                        'crm_integration' => 'Included',
                        'api_access' => true,
                        'meta_google_lead_integration' => true,
                        'relationship_manager' => 'Dedicated',
                        'support' => 'Priority support',
                    ],
                ],
            ]),
            $this->professionalPlans('developer', 'Developer', 'developer', [
                'monthly' => [7999, 17999, 34999],
                'annual' => [79990, 179990, 349990],
            ], [
                'basic' => [
                    'positioning' => 'For emerging developers with one or two projects.',
                    'features' => [
                        'developer_profiles' => 1,
                        'active_projects' => 2,
                        'unit_inventory' => 50,
                        'team_members' => 3,
                        'cities_covered' => '2',
                        'featured_project_credits' => 1,
                        'project_boost_credits' => 5,
                        'premium_search_placement' => 'Limited',
                        'developer_profile_page' => 'Basic',
                        'individual_project_microsites' => 'Basic',
                        'logo_brand_colours' => true,
                        'brochures_floor_plans' => true,
                        'project_walkthrough_videos' => '2/project',
                        'construction_updates' => 'Yes',
                        'offers_payment_plans' => true,
                        'tower_inventory_management' => 'Basic',
                        'lead_management_system' => 'Basic',
                        'lead_distribution_rules' => false,
                        'sales_team_reports' => '—',
                        'campaign_attribution' => '—',
                        'crm_integration' => '—',
                        'api_webhook_access' => false,
                        'retargeting_campaigns' => '—',
                        'homepage_visibility' => '—',
                        'relationship_manager' => 'Shared',
                        'support' => 'Phone',
                    ],
                ],
                'standard' => [
                    'positioning' => 'For established regional developers.',
                    'features' => [
                        'developer_profiles' => 3,
                        'active_projects' => 8,
                        'unit_inventory' => 250,
                        'team_members' => 10,
                        'cities_covered' => '8',
                        'featured_project_credits' => 5,
                        'project_boost_credits' => 20,
                        'premium_search_placement' => 'Priority',
                        'developer_profile_page' => 'Branded',
                        'individual_project_microsites' => 'Advanced',
                        'logo_brand_colours' => true,
                        'brochures_floor_plans' => true,
                        'project_walkthrough_videos' => '10/project',
                        'construction_updates' => 'Yes',
                        'offers_payment_plans' => true,
                        'tower_inventory_management' => 'Advanced',
                        'lead_management_system' => 'Advanced',
                        'lead_distribution_rules' => true,
                        'sales_team_reports' => 'Yes',
                        'campaign_attribution' => 'Basic',
                        'crm_integration' => 'Optional',
                        'api_webhook_access' => false,
                        'retargeting_campaigns' => 'Optional',
                        'homepage_visibility' => 'Limited',
                        'relationship_manager' => 'Dedicated',
                        'support' => 'Priority',
                    ],
                ],
                'pro' => [
                    'positioning' => 'For large developers operating across multiple cities.',
                    'features' => [
                        'developer_profiles' => 10,
                        'active_projects' => 25,
                        'unit_inventory' => 1000,
                        'team_members' => 25,
                        'cities_covered' => 'Pan-India',
                        'featured_project_credits' => 15,
                        'project_boost_credits' => 60,
                        'premium_search_placement' => 'Highest',
                        'developer_profile_page' => 'Premium microsite',
                        'individual_project_microsites' => 'Premium',
                        'logo_brand_colours' => true,
                        'brochures_floor_plans' => true,
                        'project_walkthrough_videos' => ['value' => 'Fair-use unlimited', 'is_unlimited' => true],
                        'construction_updates' => 'Yes',
                        'offers_payment_plans' => true,
                        'tower_inventory_management' => 'Advanced',
                        'lead_management_system' => 'Enterprise',
                        'lead_distribution_rules' => true,
                        'sales_team_reports' => 'Advanced',
                        'campaign_attribution' => 'Advanced',
                        'crm_integration' => 'Included',
                        'api_webhook_access' => true,
                        'retargeting_campaigns' => 'Included credits',
                        'homepage_visibility' => 'Included',
                        'relationship_manager' => 'Priority dedicated',
                        'support' => 'Enterprise support',
                    ],
                ],
            ])
        );
    }

    private function ownerPlans(): array
    {
        $plans = [
            'basic' => [
                'price' => 499,
                'positioning' => 'List one property professionally.',
                'features' => [
                    'active_property_listings' => 1,
                    'property_photos' => 10,
                    'property_videos' => '—',
                    'listing_refreshes' => 2,
                    'featured_listing_credits' => 0,
                    'search_visibility' => 'Standard',
                    'direct_buyer_enquiries' => ['value' => 'Unlimited', 'is_unlimited' => true],
                    'owner_verification_badge' => 'After verification',
                    'listing_performance_report' => 'Basic',
                    'social_sharing_tools' => true,
                    'property_valuation_report' => '—',
                    'relationship_manager' => '—',
                    'support' => 'Email',
                ],
            ],
            'standard' => [
                'price' => 999,
                'positioning' => 'Best for owners with multiple properties.',
                'features' => [
                    'active_property_listings' => 3,
                    'property_photos' => 20,
                    'property_videos' => '1 per property',
                    'listing_refreshes' => 8,
                    'featured_listing_credits' => 1,
                    'search_visibility' => 'Priority',
                    'direct_buyer_enquiries' => ['value' => 'Unlimited', 'is_unlimited' => true],
                    'owner_verification_badge' => 'After verification',
                    'listing_performance_report' => 'Detailed',
                    'social_sharing_tools' => true,
                    'property_valuation_report' => 'Basic',
                    'relationship_manager' => '—',
                    'support' => 'Email and chat',
                ],
            ],
            'pro' => [
                'price' => 1999,
                'positioning' => 'Maximum visibility for faster responses.',
                'features' => [
                    'active_property_listings' => 5,
                    'property_photos' => 30,
                    'property_videos' => '2 per property',
                    'listing_refreshes' => 20,
                    'featured_listing_credits' => 3,
                    'search_visibility' => 'Premium',
                    'direct_buyer_enquiries' => ['value' => 'Unlimited', 'is_unlimited' => true],
                    'owner_verification_badge' => 'After verification',
                    'listing_performance_report' => 'Advanced',
                    'social_sharing_tools' => true,
                    'property_valuation_report' => 'Detailed',
                    'relationship_manager' => 'Yes',
                    'support' => 'Priority phone support',
                ],
            ],
        ];

        $result = [];
        $sort = 10;

        foreach ($plans as $tier => $plan) {
            $result[] = [
                'category_slug' => 'owner',
                'role' => 'owner',
                'tier' => $tier,
                'billing_cycle' => '90_days',
                'name' => 'Owner ' . Str::title($tier),
                'slug' => "owner-{$tier}-90-days",
                'short_description' => $plan['positioning'],
                'description' => 'Owner package valid for 90 days.',
                'price' => $plan['price'],
                'duration' => 90,
                'duration_type' => 'days',
                'sort_order' => $sort++,
                'positioning' => $plan['positioning'],
                'features' => $plan['features'],
            ];
        }

        return $result;
    }

    private function professionalPlans(
        string $categorySlug,
        string $displayName,
        string $role,
        array $prices,
        array $tiers
    ): array {
        $result = [];
        $sortBase = match ($categorySlug) {
            'agent' => 100,
            'consultancy' => 200,
            'company' => 300,
            'developer' => 400,
            default => 500,
        };

        foreach (['monthly', 'annual'] as $cycleIndex => $billingCycle) {
            $duration = $billingCycle === 'monthly' ? 1 : 1;
            $durationType = $billingCycle === 'monthly' ? 'months' : 'years';

            foreach (['basic', 'standard', 'pro'] as $tierIndex => $tier) {
                $result[] = [
                    'category_slug' => $categorySlug,
                    'role' => $role,
                    'tier' => $tier,
                    'billing_cycle' => $billingCycle,
                    'name' => $displayName . ' ' . Str::title($tier) . ' ' . Str::title($billingCycle),
                    'slug' => "{$categorySlug}-{$tier}-{$billingCycle}",
                    'short_description' => $tiers[$tier]['positioning'],
                    'description' => $displayName . ' ' . Str::title($tier) . ' ' . $billingCycle . ' membership plan.',
                    'price' => $prices[$billingCycle][$tierIndex],
                    'duration' => $duration,
                    'duration_type' => $durationType,
                    'sort_order' => $sortBase + ($cycleIndex * 10) + $tierIndex + 1,
                    'positioning' => $tiers[$tier]['positioning'],
                    'features' => $tiers[$tier]['features'],
                ];
            }
        }

        return $result;
    }
}
