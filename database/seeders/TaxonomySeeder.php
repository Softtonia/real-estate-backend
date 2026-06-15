<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use Illuminate\Database\Seeder;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $purpose = Taxonomy::updateOrCreate(
            ['slug' => 'purpose'],
            [
                'parent_id' => null,
                'is_relationship' => false,
                'is_parent' => false,
                'name' => 'Purpose',
                'description' => 'Purpose taxonomy such as sell, rent, lease.',
                'is_default' => true,
                'hierarchical' => false,
                'status' => true,
                'sort_order' => 1,
                'created_by' => null,
            ]
        );

        $property = Taxonomy::updateOrCreate(
            ['slug' => 'property'],
            [
                'parent_id' => null,
                'is_relationship' => true,
                'is_parent' => true,
                'name' => 'Property',
                'description' => 'Property taxonomy for grouping property records.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 2,
                'created_by' => null,
            ]
        );

        Taxonomy::updateOrCreate(
            ['slug' => 'property-type'],
            [
                'parent_id' => $property->id,
                'is_relationship' => true,
                'is_parent' => false,
                'name' => 'Property Type',
                'description' => 'Property type taxonomy such as residential, commercial, villa, apartment.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 3,
                'created_by' => null,
            ]
        );

        Taxonomy::updateOrCreate(
            ['slug' => 'property-status'],
            [
                'parent_id' => $property->id,
                'is_relationship' => true,
                'is_parent' => false,
                'name' => 'Property Status',
                'description' => 'Property status taxonomy such as ready to move, under construction, resale.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 4,
                'created_by' => null,
            ]
        );
    }
}