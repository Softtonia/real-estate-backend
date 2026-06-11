<?php

namespace Database\Seeders;

use App\Models\PostType;
use App\Models\Taxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostTypeTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'property-listing' => [
                'purpose',
                'property-type',
                'property-status',
                'location',
                'amenities',
            ],
            'project-listing' => [
                'project-status',
                'location',
                'amenities',
            ],
            'developer-listing' => [
                'developer-type',
                'location',
            ],
            'blog' => [
                'blog-category',
                'blog-tag',
            ],
        ];

        foreach ($mapping as $postTypeSlug => $taxonomySlugs) {
            $postType = PostType::where('slug', $postTypeSlug)->first();

            if (!$postType) {
                continue;
            }

            foreach ($taxonomySlugs as $index => $taxonomySlug) {
                $taxonomy = Taxonomy::where('slug', $taxonomySlug)->first();

                if (!$taxonomy) {
                    continue;
                }

                DB::table('post_type_taxonomies')->updateOrInsert(
                    [
                        'post_type_id' => $postType->id,
                        'taxonomy_id' => $taxonomy->id,
                    ],
                    [
                        'sort_order' => $index + 1,
                        'status' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}