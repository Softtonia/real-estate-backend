<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientReviewsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('client_reviews')->insert([
            [
                'title' => 'Sumeet Sawlani',
                'short_description' => 'Bhopal',
                'client_photo' => '1755004543_sumeet.jpg',
                'review' => 'Our group has reached a huge number of people throughout the country through Urbanrealities. The response is tremendous and we are glad to be a part of your website',
                'status' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Milind Desai',
                'short_description' => 'Ahmedabad',
                'client_photo' => '1755004629_milind.jpg',
                'review' => 'I have been using Urbanrealities.com as an individual since its launch, and as a real estate professional since a few years.',
                'status' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Hem Batra',
                'short_description' => 'Delhi',
                'client_photo' => '1755004696_hembatra.jpg',
                'review' => 'Urbanrealities.com and I started in the Real Estate business at almost the same time. This web portal just provided the best platform I needed to make a launch. After 5 year, I have a l...',
                'status' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Shri Ram',
                'short_description' => 'Pune',
                'client_photo' => '1755004767_shriram.jpg',
                'review' => 'I really love this website. It has been a long time since I am using this website, which gives me all the information which one needs for a property',
                'status' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

    }
}
