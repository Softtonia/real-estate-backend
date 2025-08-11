<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        DB::table('site_settings')->insert([
            'ticket_prefix' => 'TCKT',
            'website_logo' => 'logo.png',
            'mobile_logo' => 'mobile-logo.png',
            'favicon' => 'favicon_icon_UR.png',
            'site_name' => 'Urbanrealities',
            'address' => 'Delhi',
            'mobile_number' => '7340902187',
            'email' => 'sales@softtonia.com',
            'copyright_text' => 'copyright ' . '© ' . '2025.All Right Researved.',
            'disclaimer' => 'Urbanrealities Realty Services Limited is only an intermediary offering its platform to advertise properties of Seller for a Customer/Buyer/User coming on its Website and is not and cannot be a party to or privy to or control in any manner any transactions between the Seller and the Customer/Buyer/User. All the offers and discounts on this Website have been extended by Read more',
            'site_short_description' => 'Urbanrealities Pvt. Ltd. – Your trusted real estate listing platform for buying, selling, and renting residential and commercial properties. Explore verified listings and find your dream property today',
            'subscribe_short_description' => 'Stay ahead in the property market with Urbanrealities! Get the latest listings, real estate trends, and expert tips delivered straight to your inbox.',
            'facebook' => 'https://facebook.com/urbanrealities',
            'instagram' => 'https://instagram.com/urbanrealities',
            'twitter' => 'https://twitter.com/urbanrealities',
        ]);


    }
}
