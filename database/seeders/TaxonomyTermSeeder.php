<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        $propertyTaxonomy = Taxonomy::where('slug', 'property')->first();
        $propertyTypeTaxonomy = Taxonomy::where('slug', 'property-type')->first();

        if (!$propertyTypeTaxonomy) {
            return;
        }

        $groupNames = ['Residential', 'Commercial', 'Agricultural', 'Industrial'];
        $propertyGroupTerms = [];
        $propertyTypeGroupTerms = [];

        // 1. Fetch/create parent Property terms (Taxonomy 2: Property)
        if ($propertyTaxonomy) {
            foreach ($groupNames as $idx => $gName) {
                $term = TaxonomyTerm::updateOrCreate(
                    [
                        'taxonomy_id' => $propertyTaxonomy->id,
                        'slug' => Str::slug($gName),
                    ],
                    [
                        'parent_id' => null,
                        'name' => $gName,
                        'sort_order' => $idx + 1,
                        'status' => true,
                    ]
                );
                $propertyGroupTerms[$gName] = $term;
            }
        }

        // 2. Fetch/create parent Property Type terms (Taxonomy 3: Property Type)
        foreach ($groupNames as $idx => $gName) {
            $term = TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $propertyTypeTaxonomy->id,
                    'slug' => Str::slug($gName),
                ],
                [
                    'parent_id' => null,
                    'name' => $gName,
                    'sort_order' => $idx + 1,
                    'status' => true,
                ]
            );
            $propertyTypeGroupTerms[$gName] = $term;
        }

        // 3. Define all 38 Magicbricks Property Types with exact slugs & grouping
        $propertyTypes = [
            // Residential
            ['name' => 'Apartment', 'slug' => 'apartment', 'parent' => 'Residential', 'sort' => 1],
            ['name' => 'Flat', 'slug' => 'flat', 'parent' => 'Residential', 'sort' => 2],
            ['name' => 'Villa', 'slug' => 'villa', 'parent' => 'Residential', 'sort' => 3],
            ['name' => 'Independent House', 'slug' => 'independent-house', 'parent' => 'Residential', 'sort' => 4],
            ['name' => 'Builder Floor', 'slug' => 'builder-floor', 'parent' => 'Residential', 'sort' => 5],
            ['name' => 'Residential Plot', 'slug' => 'residential-plot', 'parent' => 'Residential', 'sort' => 6],
            ['name' => 'Duplex', 'slug' => 'duplex', 'parent' => 'Residential', 'sort' => 7],
            ['name' => 'Penthouse', 'slug' => 'penthouse', 'parent' => 'Residential', 'sort' => 8],

            // Commercial
            ['name' => 'Office Space', 'slug' => 'office-space', 'parent' => 'Commercial', 'sort' => 9],
            ['name' => 'Showroom', 'slug' => 'showroom', 'parent' => 'Commercial', 'sort' => 10],
            ['name' => 'Shopping Complex / Mall', 'slug' => 'shopping-complex-mall', 'parent' => 'Commercial', 'sort' => 11],
            ['name' => 'Commercial Building', 'slug' => 'commercial-building', 'parent' => 'Commercial', 'sort' => 12],
            ['name' => 'Warehouse', 'slug' => 'warehouse', 'parent' => 'Commercial', 'sort' => 13],
            ['name' => 'Hotel', 'slug' => 'hotel', 'parent' => 'Commercial', 'sort' => 14],
            ['name' => 'Restaurant / Cafe', 'slug' => 'restaurant-cafe', 'parent' => 'Commercial', 'sort' => 15],
            ['name' => 'Business Centre', 'slug' => 'business-centre', 'parent' => 'Commercial', 'sort' => 16],
            ['name' => 'Co-working Space', 'slug' => 'co-working-space', 'parent' => 'Commercial', 'sort' => 17],

            // Agricultural
            ['name' => 'Agricultural Land / Farmland', 'slug' => 'agricultural-land-farmland', 'parent' => 'Agricultural', 'sort' => 18],
            ['name' => 'Crop Land', 'slug' => 'crop-land', 'parent' => 'Agricultural', 'sort' => 19],
            ['name' => 'Orchard / Fruit Farm', 'slug' => 'orchard-fruit-farm', 'parent' => 'Agricultural', 'sort' => 20],
            ['name' => 'Plantation Land', 'slug' => 'plantation-land', 'parent' => 'Agricultural', 'sort' => 21],
            ['name' => 'Horticultural Land', 'slug' => 'horticultural-land', 'parent' => 'Agricultural', 'sort' => 22],
            ['name' => 'Dairy Farm Land', 'slug' => 'dairy-farm-land', 'parent' => 'Agricultural', 'sort' => 23],
            ['name' => 'Poultry Farm Land', 'slug' => 'poultry-farm-land', 'parent' => 'Agricultural', 'sort' => 24],
            ['name' => 'Agricultural Farmhouse', 'slug' => 'agricultural-farmhouse', 'parent' => 'Agricultural', 'sort' => 25],
            ['name' => 'Grazing / Pasture Land', 'slug' => 'grazing-pasture-land', 'parent' => 'Agricultural', 'sort' => 26],
            ['name' => 'Irrigated Agricultural Land', 'slug' => 'irrigated-agricultural-land', 'parent' => 'Agricultural', 'sort' => 27],
            ['name' => 'Non-Irrigated Agricultural Land', 'slug' => 'non-irrigated-agricultural-land', 'parent' => 'Agricultural', 'sort' => 28],

            // Industrial
            ['name' => 'Industrial Plot', 'slug' => 'industrial-plot', 'parent' => 'Industrial', 'sort' => 29],
            ['name' => 'Factory', 'slug' => 'factory', 'parent' => 'Industrial', 'sort' => 30],
            ['name' => 'Manufacturing Unit', 'slug' => 'manufacturing-unit', 'parent' => 'Industrial', 'sort' => 31],
            ['name' => 'Industrial Shed', 'slug' => 'industrial-shed', 'parent' => 'Industrial', 'sort' => 32],
            ['name' => 'Warehouse / Godown', 'slug' => 'warehouse-godown', 'parent' => 'Industrial', 'sort' => 33],
            ['name' => 'Workshop', 'slug' => 'workshop', 'parent' => 'Industrial', 'sort' => 34],
            ['name' => 'Industrial Building', 'slug' => 'industrial-building', 'parent' => 'Industrial', 'sort' => 35],
            ['name' => 'Logistics / Distribution Centre', 'slug' => 'logistics-distribution-centre', 'parent' => 'Industrial', 'sort' => 36],
            ['name' => 'Cold Storage', 'slug' => 'cold-storage', 'parent' => 'Industrial', 'sort' => 37],
            ['name' => 'Industrial Estate', 'slug' => 'industrial-estate', 'parent' => 'Industrial', 'sort' => 38],
        ];

        foreach ($propertyTypes as $pt) {
            $gName = $pt['parent'];
            $parentTypeTerm = $propertyTypeGroupTerms[$gName] ?? null;
            $parentPropTerm = $propertyGroupTerms[$gName] ?? null;

            $typeTerm = TaxonomyTerm::updateOrCreate(
                [
                    'taxonomy_id' => $propertyTypeTaxonomy->id,
                    'slug' => $pt['slug'],
                ],
                [
                    'parent_id' => $parentTypeTerm?->id,
                    'name' => $pt['name'],
                    'description' => null,
                    'sort_order' => $pt['sort'],
                    'status' => true,
                ]
            );

            if ($parentPropTerm && DB::getSchemaBuilder()->hasTable('taxonomy_term_relations')) {
                DB::table('taxonomy_term_relations')->updateOrInsert(
                    [
                        'parent_taxonomy_term_id' => $parentPropTerm->id,
                        'taxonomy_term_id' => $typeTerm->id,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
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