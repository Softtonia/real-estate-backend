<?php

namespace Database\Seeders;

use App\Models\CustomWidget;
use App\Models\WidgetConfiguration;
use Illuminate\Database\Seeder;

class CustomWidgetSeeder extends Seeder
{
    public function run(): void
    {
        $widgets = [
            [
                'widget_name' => 'Dynamic Title',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'title',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'dynamic_title',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Excerpt',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'excerpt',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'dynamic_excerpt',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Featured Image',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'featured_image',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'dynamic_image',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Dynamic Gallery',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'gallery',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'dynamic_gallery',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Dynamic Slider',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'gallery',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'dynamic_slider',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Owner CTA',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'relation',
                            'field' => 'owner',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'owner_cta',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Post Date',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'created_at',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'post_date',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Property Id',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'system',
                            'field' => 'id',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'property_id',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
            [
                'widget_name' => 'Amenities',
                'post_type' => 'property-listing',
                'configurations' => [
                    [
                        'field_key' => 'binding',
                        'field_value' => [
                            'source' => 'relation',
                            'field' => 'amenities',
                        ],
                    ],
                    [
                        'field_key' => 'component',
                        'field_value' => [
                            'key' => 'amenities',
                            'type' => 'dynamic',
                        ],
                    ],
                ],
            ],
        ];

        foreach ($widgets as $widgetData) {
            $widget = CustomWidget::updateOrCreate(
                [
                    'slug' => CustomWidget::generateUniqueSlug($widgetData['widget_name']),
                ],
                [
                    'widget_name' => $widgetData['widget_name'],
                    'post_type' => $widgetData['post_type'],
                    'created_by' => null,
                ]
            );

            $widget->configurations()->delete();

            foreach ($widgetData['configurations'] as $configuration) {
                WidgetConfiguration::create([
                    'widget_id' => $widget->id,
                    'field_key' => $configuration['field_key'],
                    'field_value' => $configuration['field_value'],
                ]);
            }
        }
    }
}