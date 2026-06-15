<?php

namespace Database\Seeders;

use App\Models\Taxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $purpose = $this->createOrUpdateTaxonomy([
                'name' => 'Purpose',
                'slug' => 'purpose',
                'description' => 'Purpose taxonomy such as sell, rent, lease.',
                'is_relationship' => false,
                'is_parent' => false,
                'is_default' => true,
                'hierarchical' => false,
                'status' => true,
                'sort_order' => 1,
                'created_by' => null,
            ]);

            $property = $this->createOrUpdateTaxonomy([
                'name' => 'Property',
                'slug' => 'property',
                'description' => 'Property taxonomy for grouping property records.',
                'is_relationship' => true,
                'is_parent' => true,
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 2,
                'created_by' => null,
            ]);

            $listing = $this->createOrUpdateTaxonomy([
                'name' => 'Listing',
                'slug' => 'listing',
                'description' => 'Listing taxonomy for property listing records.',
                'is_relationship' => true,
                'is_parent' => true,
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 3,
                'created_by' => null,
            ]);

            $propertyType = $this->createOrUpdateTaxonomy([
                'name' => 'Property Type',
                'slug' => 'property-type',
                'description' => 'Property type taxonomy such as residential, commercial, villa, apartment.',
                'is_relationship' => true,
                'is_parent' => false,
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 4,
                'created_by' => null,
            ]);

            $propertyStatus = $this->createOrUpdateTaxonomy([
                'name' => 'Property Status',
                'slug' => 'property-status',
                'description' => 'Property status taxonomy such as ready to move, under construction, resale.',
                'is_relationship' => true,
                'is_parent' => false,
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 5,
                'created_by' => null,
            ]);

            $amenity = $this->createOrUpdateTaxonomy([
                'name' => 'Amenity',
                'slug' => 'amenity',
                'description' => 'Amenity taxonomy such as parking, pool, lift, garden.',
                'is_relationship' => true,
                'is_parent' => false,
                'is_default' => true,
                'hierarchical' => true,
                'status' => true,
                'sort_order' => 6,
                'created_by' => null,
            ]);

            $propertyType->parents()->sync([
                $property->id => [
                    'sort_order' => 1,
                    'status' => true,
                ],
                $listing->id => [
                    'sort_order' => 2,
                    'status' => true,
                ],
            ]);

            $propertyStatus->parents()->sync([
                $property->id => [
                    'sort_order' => 1,
                    'status' => true,
                ],
                $listing->id => [
                    'sort_order' => 2,
                    'status' => true,
                ],
            ]);

            $amenity->parents()->sync([
                $property->id => [
                    'sort_order' => 1,
                    'status' => true,
                ],
            ]);

            $purpose->parents()->detach();
            $purpose->children()->detach();
        });
    }

    private function createOrUpdateTaxonomy(array $data): Taxonomy
    {
        $taxonomy = Taxonomy::withTrashed()
            ->where('slug', $data['slug'])
            ->first();

        if (!$taxonomy) {
            return Taxonomy::create($data);
        }

        if ($taxonomy->trashed()) {
            $taxonomy->restore();
        }

        $taxonomy->update($data);

        return $taxonomy->fresh();
    }
}

