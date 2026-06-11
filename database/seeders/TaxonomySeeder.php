<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use Illuminate\Database\Seeder;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $taxonomies = [
            [
                'name' => 'Purpose',
                'slug' => 'purpose',
                'description' => 'Purpose taxonomy such as sell, rent, lease.',
                'is_default' => true,
                'hierarchical' => false,
                'status' => true,
                'sort_order' => 1,
                'menu_order' => 1,
            ],
            [
                'name' => 'Property',
                'slug' => 'property',
                'description' => 'Property taxonomy for grouping property records.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 2,
                'menu_order' => 2,
            ],
            [
                'name' => 'Property Type',
                'slug' => 'property-type',
                'description' => 'Property type taxonomy such as residential, commercial, villa, apartment.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 3,
                'menu_order' => 3,
            ],
            [
                'name' => 'Property Status',
                'slug' => 'property-status',
                'description' => 'Property status taxonomy such as ready to move, under construction, resale.',
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 4,
                'menu_order' => 4,
            ],
        ];

        foreach ($taxonomies as $taxonomy) {
            Taxonomy::updateOrCreate(
                [
                    'slug' => $taxonomy['slug'],
                ],
                [
                    'name' => $taxonomy['name'],
                    'description' => $taxonomy['description'],
                    'is_default' => $taxonomy['is_default'],
                    'hierarchical' => $taxonomy['hierarchical'],
                    'status' => $taxonomy['status'],
                    'sort_order' => $taxonomy['sort_order'],
                    'menu_order' => $taxonomy['menu_order'],
                    'created_by' => null,
                ]
            );
        }
    }
}