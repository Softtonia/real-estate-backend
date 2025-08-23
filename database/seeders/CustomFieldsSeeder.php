<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterOption;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomFieldsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Direct group_id use karein (change as per your DB)
            $groupId = 1;

            // Fields data
            // $fields = [

            //     [
            //         'field_label' => 'Property Type',
            //         'checkbox_type' => null,
            //         'field_name_slug' => 'property_type',
            //         'field_placeholder' => 'Select property type',
            //         'field_type' => 'select',
            //         'required' => 'yes',
            //         'post_type' => 'property_list',
            //         'template_id' => 1,
            //         'media_limit' => null,
            //         'media_size' => null,
            //         'media_format' => ['jpg', 'png'],
            //         'options' => [
            //             ['label' => 'House', 'value' => 'house'],
            //             ['label' => 'Apartment', 'value' => 'apartment'],
            //         ],
            //         'repeater' => [],
            //         'modelFields' => [
            //             ['model' => 'property_type', 'condition' => [5]],
            //             ['model' => 'purpose', 'condition' => [1, 2, 3]],
            //         ]
            //     ],
            //     [
            //         'field_label' => 'Purpose',
            //         'checkbox_type' => null,
            //         'field_name_slug' => 'purpose',
            //         'field_placeholder' => 'Select purpose',
            //         'field_type' => 'select',
            //         'required' => 'yes',
            //         'post_type' => 'property_list',
            //         'template_id' => 2,
            //         'media_limit' => null,
            //         'media_size' => null,
            //         'media_format' => [],
            //         'options' => [
            //             ['label' => 'Sell', 'value' => 'sell'],
            //             ['label' => 'Rent', 'value' => 'rent'],
            //         ],
            //         'repeater' => [],
            //         'modelFields' => [
            //             ['model' => 'purpose', 'condition' => [1, 2, 3]]
            //         ]
            //     ]
            // ];


            $fields = [
                [
                    'field_label' => 'Owner Name',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'text',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 1,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'purpose', 'condition' => [1]],
                        ['model' => 'property', 'condition' => [1]],
                    ]
                ],
                [
                    'field_label' => 'Contact Number',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'number',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 2,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'purpose', 'condition' => [1]],
                        ['model' => 'property', 'condition' => [1]],
                    ]
                ],
                [
                    'field_label' => 'email',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'text',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 3,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'purpose', 'condition' => [1]],
                        ['model' => 'property', 'condition' => [1]],
                    ]
                ],
                [
                    'field_label' => 'Price',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'number',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 4,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property', 'condition' => [1,
                        2,
                        3,
                        4,
                        22,
                        6,
                        5,
                        10,
                        7]],
                    ]
                ],
                [
                    'field_label' => 'Property Images',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'media',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 12,
                    'media_limit' => 50,
                    'media_size' => 5,
                    'media_format' => ["png","jpeg","jpg","webp"],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'purpose', 'condition' => [1]],
                        ['model' => 'property', 'condition' => [1]],
                    ]
                ],
                [
                    'field_label' => 'Bedrooms',
                    'checkbox_type' => "manually",
                    'field_placeholder' => null,
                    'field_type' => 'radio',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 6,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [
                        ['label' => '1 BHK', 'value' => '1 BHK'],
                        ['label' => '2 BHK', 'value' => '2 BHK'],
                        ['label' => '3 BHK', 'value' => '3 BHK'],
                        ['label' => '4 BHK', 'value' => '4 BHK'],
                        ['label' => '5+ BHK', 'value' => '5+ BHK'],
                    ],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property_type', 'condition' => [1, 2, 4, 5]],
                    ]
                ],
                [
                    'field_label' => 'Bathrooms',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'text',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 7,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [
                    ],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property_type', 'condition' => [1, 2, 4, 5]],
                        ['model' => 'property_status', 'condition' => [4]],
                    ]
                ],
                [
                    'field_label' => 'Balconies',
                    'checkbox_type' => 'manually',
                    'field_placeholder' => null,
                    'field_type' => 'radio',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 7,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [
                         ['label' => '1', 'value' => '1'],
                        ['label' => '2', 'value' => '2'],
                        ['label' => '3', 'value' => '3'],
                        ['label' => '4', 'value' => '4'],
                        ['label' => '5+', 'value' => '5+'],
                    ],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property_type', 'condition' => [1, 2, 4, 5,8]],
                    ]
                ],
                [
                    'field_label' => 'Furnishing Status',
                    'checkbox_type' => 'manually',
                    'field_placeholder' => null,
                    'field_type' => 'radio',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 9,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [
                        ['label' => 'Furnished', 'value' => 'Furnished'],
                        ['label' => 'Un-Furnished', 'value' => 'Un-Furnished'],
                        ['label' => 'Semi-Furnished', 'value' => 'Semi-Furnished'],
                    ],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property_type', 'condition' => [1, 2, 4, 5,6,7,8]],

                    ]
                ],
                [
                    'field_label' => 'Possession Date',
                    'checkbox_type' => null,
                    'field_placeholder' => null,
                    'field_type' => 'text',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 10,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property_type', 'condition' => [1, 2, 4, 5, 3]],
                        ['model' => 'property_status', 'condition' => [1, 3, 5, 6, 7]],
                    ]
                ],
                [
                    'field_label' => 'Area Sq Ft',
                    'checkbox_type' => null,
                    'field_placeholder' => 'Enter the Area_sq.ft',
                    'field_type' => 'number',
                    'required' => 'yes',
                    'post_type' => 'property_list',
                    'template_id' => 5,
                    'media_limit' => null,
                    'media_size' => null,
                    'media_format' => [],
                    'options' => [],
                    'repeater' => [],
                    'modelFields' => [
                        ['model' => 'property', 'condition' => [1,2,3,4]],
                    ]
                ],









            ];


            // Insert each field
            foreach ($fields as $fieldData) {
                $mediaFormat = !empty($fieldData['media_format']) ? implode(',', $fieldData['media_format']) : null;

                $modelFieldsData = [];
                if (!empty($fieldData['modelFields'])) {
                    foreach ($fieldData['modelFields'] as $modelField) {
                        $modelFieldsData[] = [
                            'model' => $modelField['model'],
                            'condition' => $modelField['condition'],
                        ];
                    }
                }

                $field = CustomField::create([
                    'group_id' => $groupId,
                    'field_label' => $fieldData['field_label'],
                    'field_name_slug' => Str::slug($fieldData['field_label']),
                    'field_placeholder' => $fieldData['field_placeholder'] ?? null,
                    'field_type' => $fieldData['field_type'],
                    'required' => $fieldData['required'],
                    'checkbox_type' => $fieldData['checkbox_type'] ?? null,
                    'post_type' => $fieldData['post_type'],
                    'template_id' => $fieldData['template_id'] ?? null,
                    'media_limit' => $fieldData['media_limit'] ?? null,
                    'media_size' => $fieldData['media_size'] ?? null,
                    'media_format' => $mediaFormat,
                    'model_fields' => json_encode($modelFieldsData),
                ]);

                // Options insert karein
                if (in_array($fieldData['field_type'], ['select', 'checkbox', 'radio']) && !empty($fieldData['options'])) {
                    foreach ($fieldData['options'] as $option) {
                        CustomFieldOption::create([
                            'custom_field_id' => $field->id,
                            'type' => $fieldData['field_type'],
                            'name' => $option['label'],
                            'value' => $option['value'],
                        ]);
                    }
                }

                // Repeater fields insert karein
                if ($fieldData['field_type'] === 'repeater' && !empty($fieldData['repeater'])) {
                    foreach ($fieldData['repeater'] as $repeaterItem) {
                        $fieldMediaFormat = isset($repeaterItem['fieldMediaFormat'])
                            ? (is_array($repeaterItem['fieldMediaFormat'])
                                ? implode(',', $repeaterItem['fieldMediaFormat'])
                                : $repeaterItem['fieldMediaFormat'])
                            : null;

                        $repeaterField = CustomFieldRepeater::create([
                            'group_id' => $groupId,
                            'custom_field_id' => $field->id,
                            'field_label' => $repeaterItem['fieldName'],
                            'field_type' => $repeaterItem['fieldType'],
                            'field_name_slug' => $repeaterItem['field_name_slug'],
                            'field_placeholder' => $repeaterItem['fieldPlaceholder'] ?? null,
                            'media_format' => $fieldMediaFormat,
                            'media_limit' => $repeaterItem['fieldMediaLimit'] ?? null,
                            'media_size' => $repeaterItem['fieldMediaSize'] ?? null,
                        ]);

                        if (!empty($repeaterItem['fieldOptions'])) {
                            foreach ($repeaterItem['fieldOptions'] as $option) {
                                CustomFieldRepeaterOption::create([
                                    'custom_field_repeater_id' => $repeaterField->id,
                                    'type' => $repeaterItem['fieldType'],
                                    'name' => $option['name'],
                                    'value' => $option['value'],
                                ]);
                            }
                        }
                    }
                }
            }
        });
    }
}
