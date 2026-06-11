<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TaxonomyTermSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPurposeTerms();
        $this->seedPropertyTerms();
        $this->seedPropertyTypeTerms();
        $this->seedPropertyStatusTerms();
    }

    private function seedPurposeTerms(): void
    {
        $taxonomy = Taxonomy::where('slug', 'purpose')->first();

        if (!$taxonomy) {
            return;
        }

        $purposes = [
            [
                'name' => 'Sell',
                'slug' => 'sell',
                'sort_order' => 1,
            ],
            [
                'name' => 'Rent',
                'slug' => 'rent',
                'sort_order' => 2,
            ],
        ];

        foreach ($purposes as $purpose) {
            TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $taxonomy->id,
                    'slug' => $purpose['slug'],
                ],
                [
                    'parent_id' => null,
                    'name' => $purpose['name'],
                    'description' => null,
                    'sort_order' => $purpose['sort_order'],
                    'status' => true,
                ]
            );
        }
    }

    private function seedPropertyTerms(): void
    {
        $taxonomy = Taxonomy::where('slug', 'property')->first();

        if (!$taxonomy) {
            return;
        }

        $properties = [
            [
                'name' => 'Residential',
                'slug' => 'residential',
                'sort_order' => 1,
            ],
            [
                'name' => 'Commercial',
                'slug' => 'commercial',
                'sort_order' => 2,
            ],
            [
                'name' => 'Agricultural',
                'slug' => 'agricultural',
                'sort_order' => 3,
            ],
            [
                'name' => 'Industrial',
                'slug' => 'industrial',
                'sort_order' => 4,
            ],
        ];

        foreach ($properties as $property) {
            TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $taxonomy->id,
                    'slug' => $property['slug'],
                ],
                [
                    'parent_id' => null,
                    'name' => $property['name'],
                    'description' => null,
                    'sort_order' => $property['sort_order'],
                    'status' => true,
                ]
            );
        }
    }

    private function seedPropertyTypeTerms(): void
    {
        $taxonomy = Taxonomy::where('slug', 'property-type')->first();

        if (!$taxonomy) {
            return;
        }

        /*
         * Parent terms for property-type taxonomy.
         * Ye parents same taxonomy ke andar banenge, taki hierarchy properly work kare.
         */
        $parents = [
            'Residential' => 1,
            'Commercial' => 2,
            'Agricultural' => 3,
            'Industrial' => 4,
        ];

        $parentIds = [];

        foreach ($parents as $parentName => $sortOrder) {
            $parentSlug = Str::slug($parentName);

            $parent = TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $taxonomy->id,
                    'slug' => $parentSlug,
                ],
                [
                    'parent_id' => null,
                    'name' => $parentName,
                    'description' => null,
                    'sort_order' => $sortOrder,
                    'status' => true,
                ]
            );

            $parentIds[$parentSlug] = $parent->id;
        }

        $propertyTypes = [
            [
                'name' => 'Apartments',
                'parent' => 'Residential',
                'sort_order' => 1,
            ],
            [
                'name' => 'Independent Houses',
                'parent' => 'Residential',
                'sort_order' => 2,
            ],
            [
                'name' => 'Plots',
                'parent' => 'Residential',
                'sort_order' => 3,
            ],
            [
                'name' => 'Townhouses',
                'parent' => 'Residential',
                'sort_order' => 4,
            ],
            [
                'name' => 'Bungalows',
                'parent' => 'Residential',
                'sort_order' => 5,
            ],
            [
                'name' => 'Office Spaces',
                'parent' => 'Commercial',
                'sort_order' => 6,
            ],
            [
                'name' => 'Retail Spaces',
                'parent' => 'Commercial',
                'sort_order' => 7,
            ],
            [
                'name' => 'Buildings',
                'parent' => 'Commercial',
                'sort_order' => 8,
            ],
            [
                'name' => 'Hospitality Properties',
                'parent' => 'Commercial',
                'sort_order' => 9,
            ],
            [
                'name' => 'Warehousing Spaces',
                'parent' => 'Commercial',
                'sort_order' => 10,
            ],
            [
                'name' => 'Institutional Properties',
                'parent' => 'Commercial',
                'sort_order' => 11,
            ],
            [
                'name' => 'Pg',
                'parent' => 'Commercial',
                'sort_order' => 12,
            ],
            [
                'name' => 'Room/Bed in a Shared-Flat',
                'parent' => 'Commercial',
                'sort_order' => 13,
            ],
            [
                'name' => 'Crop-Based Agricultural Land',
                'parent' => 'Agricultural',
                'sort_order' => 14,
            ],
            [
                'name' => 'Horticulture Land',
                'parent' => 'Agricultural',
                'sort_order' => 15,
            ],
            [
                'name' => 'Plantation Land',
                'parent' => 'Agricultural',
                'sort_order' => 16,
            ],
            [
                'name' => 'Organic Farmland',
                'parent' => 'Agricultural',
                'sort_order' => 17,
            ],
            [
                'name' => 'Fallow Land',
                'parent' => 'Agricultural',
                'sort_order' => 18,
            ],
            [
                'name' => 'Pasture Land',
                'parent' => 'Agricultural',
                'sort_order' => 19,
            ],
            [
                'name' => 'Agroforestry Land',
                'parent' => 'Agricultural',
                'sort_order' => 20,
            ],
            [
                'name' => 'Industrial Land',
                'parent' => 'Industrial',
                'sort_order' => 21,
            ],
            [
                'name' => 'Industrial Sheds',
                'parent' => 'Industrial',
                'sort_order' => 22,
            ],
            [
                'name' => 'Manufacturing Units',
                'parent' => 'Industrial',
                'sort_order' => 23,
            ],
            [
                'name' => 'Logistics Facilities',
                'parent' => 'Industrial',
                'sort_order' => 24,
            ],
            [
                'name' => 'Industrial Parks',
                'parent' => 'Industrial',
                'sort_order' => 25,
            ],
            [
                'name' => 'Logistics Parks',
                'parent' => 'Industrial',
                'sort_order' => 26,
            ],
            [
                'name' => 'Hazardous Industry Zones',
                'parent' => 'Industrial',
                'sort_order' => 27,
            ],
            [
                'name' => 'Textile',
                'parent' => 'Industrial',
                'sort_order' => 28,
            ],
        ];

        foreach ($propertyTypes as $type) {
            $parentSlug = Str::slug($type['parent']);
            $parentId = $parentIds[$parentSlug] ?? null;

            TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $taxonomy->id,
                    'slug' => Str::slug($type['name']),
                ],
                [
                    'parent_id' => $parentId,
                    'name' => $type['name'],
                    'description' => null,
                    'sort_order' => $type['sort_order'],
                    'status' => true,
                ]
            );
        }
    }

    private function seedPropertyStatusTerms(): void
    {
        $taxonomy = Taxonomy::where('slug', 'property-status')->first();

        if (!$taxonomy) {
            return;
        }

        $statuses = [
            [
                'name' => 'Ready To Move',
                'slug' => 'ready-to-move',
                'sort_order' => 1,
            ],
            [
                'name' => 'Under Construction',
                'slug' => 'under-construction',
                'sort_order' => 2,
            ],
            [
                'name' => 'New Launch',
                'slug' => 'new-launch',
                'sort_order' => 3,
            ],
            [
                'name' => 'Resale',
                'slug' => 'resale',
                'sort_order' => 4,
            ],
        ];

        foreach ($statuses as $status) {
            TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $taxonomy->id,
                    'slug' => $status['slug'],
                ],
                [
                    'parent_id' => null,
                    'name' => $status['name'],
                    'description' => null,
                    'sort_order' => $status['sort_order'],
                    'status' => true,
                ]
            );
        }
    }
}