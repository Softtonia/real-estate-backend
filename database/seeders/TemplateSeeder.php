<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $data = [
            ["show" => "Owner.name", "name" => "ownername", "type" => "property_list"],
            ["show" => "Contact.number", "name" => "contactnumber", "type" => "property_list"],
            ["show" => "Email", "name" => "email", "type" => "property_list"],
            ["show" => "Property.price", "name" => "propertyprice", "type" => "property_list"],
            ["show" => "Area.sq.ft", "name" => "areasqft", "type" => "property_list"],
            ["show" => "Bedrooms", "name" => "bedrooms", "type" => "property_list"],
            ["show" => "Bathrooms", "name" => "bathrooms", "type" => "property_list"],
            ["show" => "Balconies", "name" => "balconies", "type" => "property_list"],
            ["show" => "Furnishing.status", "name" => "furnishingstatus", "type" => "property_list"],
            ["show" => "Possession.date", "name" => "possessiondate", "type" => "property_list"],
            ["show" => "Property.images", "name" => "propertyimages", "type" => "property_list"],
            ["show" => "Property.video", "name" => "propertyvideo", "type" => "property_list"],
            ["show" => "Property.description", "name" => "propertydescription", "type" => "property_list"],
        ];

        foreach ($data as $item) {
            DB::table('custom_field_unique_codes')->insert([
                'name'       => $item['show'], // display name
                'slug'       => $item['name'], // machine name
                'post_type'  => $item['type'],
                'status'     => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
