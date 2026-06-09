<?php

namespace Database\Seeders;

use App\Models\PostType;
use Illuminate\Database\Seeder;

class DefaultPostTypeSeeder extends Seeder
{
    public function run(): void
    {
        $postTypes = [
            [
                'name' => 'Users',
                'slug' => 'users',
                'description' => 'Default system post type for users.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'custom_fields',
                ],
                'created_by' => null,
                'sort_order' => 1,
            ],
            [
                'name' => 'Blogs',
                'slug' => 'blogs',
                'description' => 'Default post type for blog posts.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'content',
                    'excerpt',
                    'featured_image',
                    'custom_fields',
                    'taxonomies',
                ],
                'created_by' => null,
                'sort_order' => 2,
            ],
            [
                'name' => 'FAQs',
                'slug' => 'faqs',
                'description' => 'Default post type for frequently asked questions.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'content',
                    'custom_fields',
                    'taxonomies',
                ],
                'created_by' => null,
                'sort_order' => 3,
            ],
            [
                'name' => 'Property Listings',
                'slug' => 'property-listings',
                'description' => 'Default post type for property listings.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'content',
                    'excerpt',
                    'featured_image',
                    'custom_fields',
                    'taxonomies',
                    'gallery',
                ],
                'created_by' => null,
                'sort_order' => 4,
            ],
            [
                'name' => 'Developer Listings',
                'slug' => 'developer-listings',
                'description' => 'Default post type for developer listings.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'content',
                    'excerpt',
                    'featured_image',
                    'custom_fields',
                    'taxonomies',
                    'gallery',
                ],
                'created_by' => null,
                'sort_order' => 5,
            ],
            [
                'name' => 'Project Listings',
                'slug' => 'project-listings',
                'description' => 'Default post type for project listings.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'content',
                    'excerpt',
                    'featured_image',
                    'custom_fields',
                    'taxonomies',
                    'gallery',
                ],
                'created_by' => null,
                'sort_order' => 6,
            ],
        ];

        foreach ($postTypes as $postType) {
            PostType::updateOrCreate(
                [
                    'slug' => $postType['slug'],
                ],
                $postType
            );
        }
    }
}