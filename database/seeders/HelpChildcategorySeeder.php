<?php

namespace Database\Seeders;

use App\Models\HelpChildcategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HelpChildcategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $childCategories = [
            ['help_category_id' => 1, 'help_subcategory_id' => 1, 'name' => 'New Registration'],
            ['help_category_id' => 1, 'help_subcategory_id' => 1, 'name' => 'Login'],
            ['help_category_id' => 1, 'help_subcategory_id' => 2, 'name' => 'How do I Deactivate my account?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 2, 'name' => 'I have already rented/sold out my Property and want to Deactivate my account. What should I do?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 2, 'name' => 'How do I re-activate my account?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 2, 'name' => 'Can I re-activate my account using the same profile?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 2, 'name' => 'How to re-activate my account that was deactivated through online mailer?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'I have created my account with wrong Email ID. How to correct it?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'I want to update my registered Email ID. Is it possible?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'How to update the alternate Email address?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'How to change my Profile type?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'How to change my Profile name?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'How to update the city in my address?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 3, 'name' => 'How to hide my contact number?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 4, 'name' => 'How can I reset my password?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 4, 'name' => 'I forgot my password. Can I get it on email or mobile?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 4, 'name' => 'How can I make my password strong?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 5, 'name' => 'How can I change my email ID?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 5, 'name' => 'Can I make my Alternate email as my Primary email?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'How to change my mobile number?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'I have lost my mobile. How can I login?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'Can I create an account without a mobile number?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'My registered mobile number is not in use anymore. How to update my new number?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'Not receiving SMS on my registered mobile number. What should I do?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'Registered number belongs to someone else in the family. How to update it?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'Can I create a new account using the same number that is registered with my existing account?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 6, 'name' => 'Can I create a new account with same mobile number and email that was registered with my deactivated account?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 7, 'name' => 'I do not want any calls from the sales department. What should I do?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 7, 'name' => 'Why am I not receiving any SMS alerts for responses on my Property?'],
            ['help_category_id' => 1, 'help_subcategory_id' => 7, 'name' => 'Why am I not receiving Email alerts for responses on my Property?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 8, 'name' => 'What is a free listing?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 8, 'name' => 'How many free listings can I post on Magicbricks?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 8, 'name' => 'Is there any limit on the responses on a free listing?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 8, 'name' => 'My Magicbricks Free listing package has expired. What happens next?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 8, 'name' => 'How to convert free listing to paid listing?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'How to post a Property on Magicbricks?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'How many free listings can I post on Magicbricks?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'When will my Property become visible on the site?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'How can I Post a Print Ad from my account?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'Is RERA ID compulsory for owners?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'For how long will my property listing be visible on Magicbricks?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'Do I need to register to post a property?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'What documents I need to post property?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'The property type I am looking for is not available on site. What to do?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'How can I get suggestions on my Property price?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'Getting error while posting Property. What to do?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'Why is my Property being considered as \'Doubtful Listing\' on the basis of the price?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'My Property description has not been updated yet. What should I do?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'I haven’t received any call yet for Property valuation. Where to report it?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'I have had a call for Property valuation but it’s not done yet. What to do?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'My Property valuation looks incorrect. How to get it updated?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 9, 'name' => 'Why am I not able to see the contact details of my leads?'],
            ['help_category_id' => 2, 'help_subcategory_id' => 10, 'name' => 'Property price/rent amount'],
            ['help_category_id' => 2, 'help_subcategory_id' => 10, 'name' => 'Property description'],
        ];

        foreach ($childCategories as $data) {
            HelpChildcategory::create($data);
        }
    }
}
