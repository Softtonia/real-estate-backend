<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('lead_types')->insert([
            [
                'name' => 'Buyer Lead',
                'slug' => 'buyer-lead',
                'description' => 'Someone interested in buying a property.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Seller Lead',
                'slug' => 'seller-lead',
                'description' => 'Someone who wants to sell their property.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Rental Lead (Tenant)',
                'slug' => 'rental-lead-tenant',
                'description' => 'Someone looking to rent a property.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Landlord Lead',
                'slug' => 'landlord-lead',
                'description' => 'Property owner looking to rent out property.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Investor Lead',
                'slug' => 'investor-lead',
                'description' => 'Someone interested in investment opportunities.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Commercial Lead',
                'slug' => 'commercial-lead',
                'description' => 'Related to commercial properties (office, retail, warehouse).',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Residential Lead',
                'slug' => 'residential-lead',
                'description' => 'Related to residential properties (flats, villas, plots).',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Referral Lead',
                'slug' => 'referral-lead',
                'description' => 'A lead coming from referral partners or existing clients.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Agent Lead',
                'slug' => 'agent-lead',
                'description' => 'A broker/agent reaching out for collaboration.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Other',
                'slug' => 'other',
                'description' => 'For any enquiry that doesn’t fit predefined categories.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
