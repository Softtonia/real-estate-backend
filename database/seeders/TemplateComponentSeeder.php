<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TemplateComponent;

class TemplateComponentSeeder extends Seeder
{
    public function run(): void
    {
        $components = [
            [
                'component_name' => 'Property Title',
                'component_key' => 'property_title',
                'component_type' => 'dynamic',
                'icon' => 'heading',
            ],
            [
                'component_name' => 'Property Price',
                'component_key' => 'property_price',
                'component_type' => 'dynamic',
                'icon' => 'currency',
            ],
            [
                'component_name' => 'Property Gallery',
                'component_key' => 'property_gallery',
                'component_type' => 'dynamic',
                'icon' => 'image',
            ],
            [
                'component_name' => 'Property Description',
                'component_key' => 'property_description',
                'component_type' => 'dynamic',
                'icon' => 'text',
            ],
            [
                'component_name' => 'Property Location Map',
                'component_key' => 'property_map',
                'component_type' => 'dynamic',
                'icon' => 'map',
            ],
            [
                'component_name' => 'Text Block',
                'component_key' => 'text_block',
                'component_type' => 'static',
                'icon' => 'type',
            ],
            [
                'component_name' => 'Image Block',
                'component_key' => 'image_block',
                'component_type' => 'static',
                'icon' => 'image',
            ],
        ];

        foreach ($components as $component) {
            TemplateComponent::updateOrCreate(
                ['component_key' => $component['component_key']],
                $component
            );
        }
    }
}