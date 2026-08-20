<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClientReview;

class ClientReviewsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reviews = [
            [
                'title' => 'Sumeet Sawlani',
                'short_description' => 'Real Estate Developer, Bhopal',
                'client_photo' => '1755004543_sumeet.jpg',
                'review' => 'Our group has reached a huge number of genuine property buyers throughout the country through Urbanrealities. The response is tremendous and we are glad to be a featured developer on your website.',
                'rating' => 5,
                'status' => '1',
            ],
            [
                'title' => 'Milind Desai',
                'short_description' => 'Consultant & Property Advisor, Ahmedabad',
                'client_photo' => '1755004629_milind.jpg',
                'review' => 'I have been using Urbanrealities as an individual since its launch, and as a real estate professional for years. The lead conversion rate is fantastic compared to other platforms.',
                'rating' => 5,
                'status' => '1',
            ],
            [
                'title' => 'Hem Batra',
                'short_description' => 'Agency Owner, Delhi NCR',
                'client_photo' => '1755004696_hembatra.jpg',
                'review' => 'Urbanrealities provided the best platform I needed when launching my property agency. After 5 years, my business network has expanded nationwide with verified clients.',
                'rating' => 5,
                'status' => '1',
            ],
            [
                'title' => 'Shri Ram Sharma',
                'short_description' => 'Property Investor, Pune',
                'client_photo' => '1755004767_shriram.jpg',
                'review' => 'I really love this website. It gives me all the verified property market information, price trends, and locality analysis which an investor needs before buying.',
                'rating' => 4,
                'status' => '1',
            ],
            [
                'title' => 'Ananya Reddy',
                'short_description' => 'Homebuyer, Hyderabad',
                'client_photo' => '1755004820_ananya.jpg',
                'review' => 'Finding our dream 3BHK flat in Gachibowli was effortless. The direct contact with verified sellers and transparent filter options made the entire journey seamless.',
                'rating' => 5,
                'status' => '1',
            ],
            [
                'title' => 'Vikramaditya Mehta',
                'short_description' => 'Commercial Broker, Mumbai',
                'client_photo' => '1755004910_vikram.jpg',
                'review' => 'The commercial property listings feature and instant inquiry alerts have streamlined my office space leasing business completely. Highly recommended for real estate professionals!',
                'rating' => 5,
                'status' => '1',
            ],
        ];

        foreach ($reviews as $reviewData) {
            ClientReview::updateOrCreate(
                ['title' => $reviewData['title']],
                $reviewData
            );
        }
    }
}
