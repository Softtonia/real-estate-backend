<?php

namespace Database\Seeders;

use App\Models\HelpSubcategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HelpSubcategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subcategories = [
            ['help_category_id' => 1, 'name' => 'New Registration & Login'],
            ['help_category_id' => 1, 'name' => 'Account Deactivation/Re-activation'],
            ['help_category_id' => 1, 'name' => 'My Profile'],
            ['help_category_id' => 1, 'name' => 'Password Settings'],
            ['help_category_id' => 1, 'name' => 'Update Email Address'],
            ['help_category_id' => 1, 'name' => 'Update Mobile Number'],
            ['help_category_id' => 1, 'name' => 'Manage Calls/ Alerts'],

            ['help_category_id' => 2, 'name' => 'Free Property Listing'],
            ['help_category_id' => 2, 'name' => 'Posting Property'],
            ['help_category_id' => 2, 'name' => 'Edit/Update Property Details'],
            ['help_category_id' => 2, 'name' => 'Locality Update'],
            ['help_category_id' => 2, 'name' => 'Upload/Edit Photos'],
            ['help_category_id' => 2, 'name' => 'Property Status'],
            ['help_category_id' => 2, 'name' => 'Magic Cash'],
            ['help_category_id' => 2, 'name' => 'Deactivate/Reactivate Property'],

            ['help_category_id' => 3, 'name' => 'View Responses on Property Posted'],
            ['help_category_id' => 3, 'name' => 'Download Responses on Property Posted'],
            ['help_category_id' => 3, 'name' => 'Protection from online frauds'],

            ['help_category_id' => 4, 'name' => 'Planning to Buy Ad Packages'],
            ['help_category_id' => 4, 'name' => 'Package Queries'],
            ['help_category_id' => 4, 'name' => 'Package Activation'],
            ['help_category_id' => 4, 'name' => 'Package Activation'], // Duplicate intentionally kept
            ['help_category_id' => 4, 'name' => 'Package Services'],
        ];

        foreach ($subcategories as $data) {
            HelpSubcategory::create($data);
        }
    }
}
