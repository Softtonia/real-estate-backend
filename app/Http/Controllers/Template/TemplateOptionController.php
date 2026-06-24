<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class TemplateOptionController extends Controller
{
    public function options()
    {
        return response()->json([
            'status' => true,
            'message' => 'Template options fetched successfully.',
            'data' => [
                'template_types' => [
                    [
                        'label' => 'Single Post',
                        'value' => 'single_post',
                    ],
                    [
                        'label' => 'Page',
                        'value' => 'page',
                    ],
                    [
                        'label' => 'Section',
                        'value' => 'section',
                    ],
                ],

                'condition_types' => [
                    [
                        'label' => 'Include',
                        'value' => 'include',
                    ],
                    [
                        'label' => 'Exclude',
                        'value' => 'exclude',
                    ],
                ],

                'source_types' => [
                    [
                        'label' => 'Post Type',
                        'value' => 'post_type',
                    ],
                    [
                        'label' => 'Taxonomy',
                        'value' => 'taxonomy',
                    ],
                ],

                'post_types' => $this->postTypes(),
                'taxonomies' => $this->taxonomiesWithTerms(),
            ],
        ]);
    }

    private function postTypes(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('post_types')) {
            return [
                [
                    'label' => 'Property Listing',
                    'value' => 'property-listing',
                ],
                [
                    'label' => 'Project Listing',
                    'value' => 'project-listing',
                ],
                [
                    'label' => 'Developer Listing',
                    'value' => 'developer-listing',
                ],
            ];
        }

        return DB::table('post_types')
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get()
            ->map(function ($postType) {
                return [
                    'id' => $postType->id,
                    'label' => $postType->name,
                    'value' => $postType->slug,
                ];
            })
            ->values()
            ->toArray();
    }

    private function taxonomiesWithTerms(): array
    {
        if (
            !DB::getSchemaBuilder()->hasTable('taxonomies') ||
            !DB::getSchemaBuilder()->hasTable('taxonomy_terms')
        ) {
            return [];
        }

        $taxonomies = DB::table('taxonomies')
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get();

        return $taxonomies->map(function ($taxonomy) {
            $terms = DB::table('taxonomy_terms')
                ->where('taxonomy_id', $taxonomy->id)
                ->select('id', 'name', 'slug')
                ->orderBy('name')
                ->get()
                ->map(function ($term) {
                    return [
                        'id' => $term->id,
                        'label' => $term->name,
                        'value' => $term->id,
                        'slug' => $term->slug,
                    ];
                })
                ->values()
                ->toArray();

            return [
                'id' => $taxonomy->id,
                'label' => $taxonomy->name,
                'value' => $taxonomy->slug,
                'terms' => $terms,
            ];
        })
        ->values()
        ->toArray();
    }
}