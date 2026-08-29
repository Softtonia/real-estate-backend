<?php

namespace Database\Seeders;

use App\Models\PostType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultPostTypeSeeder extends Seeder
{
    public function run(): void
    {
        $postTypes = [
            [
                'name' => 'Property Listing',
                'slug' => 'property-listing',
                'description' => 'Manage property listings.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'excerpt',
                    'editor',
                    'custom_fields',
                    'taxonomies',
                ],
                'sort_order' => 1,
                'menu_order' => 1,
            ],
            [
                'name' => 'Project Listing',
                'slug' => 'project-listing',
                'description' => 'Manage real estate projects.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'excerpt',
                    'editor',
                    'custom_fields',
                    'taxonomies',
                ],
                'sort_order' => 2,
                'menu_order' => 2,
            ],
            [
                'name' => 'Developer Listing',
                'slug' => 'developer-listing',
                'description' => 'Manage developer profiles.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'excerpt',
                    'editor',
                    'custom_fields',
                    'taxonomies',
                ],
                'sort_order' => 3,
                'menu_order' => 3,
            ],
            [
                'name' => 'Blog',
                'slug' => 'blog',
                'description' => 'Manage blog posts.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'excerpt',
                    'editor',
                    'featured_image',
                    'categories',
                    'tags',
                ],
                'sort_order' => 4,
                'menu_order' => 4,
            ],
            [
                'name' => 'Page',
                'slug' => 'page',
                'description' => 'Manage static pages.',
                'is_default' => true,
                'status' => true,
                'supports' => [
                    'title',
                    'editor',
                    'featured_image',
                ],
                'sort_order' => 5,
                'menu_order' => 5,
            ],
        ];

        foreach ($postTypes as $postType) {
            PostType::withTrashed()->updateOrCreate(
                [
                    'slug' => $postType['slug'],
                ],
                [
                    'name' => $postType['name'],
                    'description' => $postType['description'],
                    'is_default' => $postType['is_default'],
                    'status' => $postType['status'],
                    'supports' => $postType['supports'],
                    'sort_order' => $postType['sort_order'],
                    'menu_order' => $postType['menu_order'],
                    'created_by' => null,
                    'deleted_at' => null,
                ]
            );
        }
    }
}