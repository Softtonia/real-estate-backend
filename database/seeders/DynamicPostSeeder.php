<?php

namespace Database\Seeders;

use App\Models\DynamicPost;
use App\Models\PostType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DynamicPostSeeder extends Seeder
{
    public function run(): void
    {
        $posts = [
            [
                'post_type_slug' => 'property-listing',
                'title' => 'Luxury Villa in Mohali',
                'excerpt' => 'Premium villa with modern amenities.',
                'content' => 'This is a premium villa located in Mohali with spacious rooms, parking, and luxury amenities.',
                'status' => 'published',
                'sort_order' => 1,
            ],
            [
                'post_type_slug' => 'property-listing',
                'title' => 'Apartment in Zirakpur',
                'excerpt' => 'Ready to move apartment in Zirakpur.',
                'content' => 'A ready to move apartment suitable for families and investors.',
                'status' => 'published',
                'sort_order' => 2,
            ],
            [
                'post_type_slug' => 'project-listing',
                'title' => 'Green Valley Project',
                'excerpt' => 'Upcoming residential project.',
                'content' => 'Green Valley is an upcoming residential project with premium amenities.',
                'status' => 'published',
                'sort_order' => 1,
            ],
            [
                'post_type_slug' => 'developer-listing',
                'title' => 'ABC Developers',
                'excerpt' => 'Trusted real estate developer.',
                'content' => 'ABC Developers is known for quality construction and timely delivery.',
                'status' => 'published',
                'sort_order' => 1,
            ],
        ];

        foreach ($posts as $post) {
            $postType = PostType::where('slug', $post['post_type_slug'])->first();

            if (!$postType) {
                continue;
            }

            DynamicPost::updateOrCreate(
                [
                    'post_type_id' => $postType->id,
                    'slug' => Str::slug($post['title']),
                ],
                [
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'content' => $post['content'],
                    'status' => $post['status'],
                    'author_id' => null,
                    'parent_id' => null,
                    'published_at' => now(),
                    'sort_order' => $post['sort_order'],
                ]
            );
        }
    }
}