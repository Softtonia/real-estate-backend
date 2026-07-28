<?php

namespace Database\Seeders;

use App\Models\Media;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MediaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mediaData = [
            [
                'icon_css_id' => 'Scenic views-ur',
                'icon_name' => 'Scenic views',
                'media_icon' => '/uploads/media_icons/1749013663_Scenic_views.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Bathroom-ur',
                'icon_name' => 'Bathroom',
                'media_icon' => '/uploads/media_icons/1749013891_CilBathroom.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Bedroom and laundry-ur',
                'icon_name' => 'Bedroom and laundry',
                'media_icon' => '/uploads/media_icons/1749014028_CbiBedroomAltNumbered.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Entertainment-ur',
                'icon_name' => 'Entertainment',
                'media_icon' => '/uploads/media_icons/1749014117_IconParkOutlineEntertainment.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Family-ur',
                'icon_name' => 'Family',
                'media_icon' => '/uploads/media_icons/1749014203_MaterialSymbolsFamilyRestroom.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Heating and cooling-ur',
                'icon_name' => 'Heating and cooling',
                'media_icon' => '/uploads/media_icons/1749014303_StreamlineTravelHotelAirConditionerHeatingAcAirHvacCoolCoolingColdHotConditioning.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Home safety-ur',
                'icon_name' => 'Home safety',
                'media_icon' => '/uploads/media_icons/1749014393_StreamlineInterfaceSecurityShield4ShieldProtectionSecurityDefendCrimeWarCover.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Internet and office-ur',
                'icon_name' => 'Internet and office',
                'media_icon' => '/uploads/media_icons/1749014587_IconoirInternet.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Kitchen and dining-ur',
                'icon_name' => 'Kitchen and dining',
                'media_icon' => '/uploads/media_icons/1749014724_MaterialSymbolsDining.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Location features-ur',
                'icon_name' => 'Location features',
                'media_icon' => '/uploads/media_icons/1749014853_IcRoundLocationOn.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Outdoor-ur',
                'icon_name' => 'Outdoor',
                'media_icon' => '/uploads/media_icons/1749014972_MaterialSymbolsCameraOutdoorOutlineSharp.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Parking and facilities-ur',
                'icon_name' => 'Parking and facilities',
                'media_icon' => '/uploads/media_icons/1749015104_UilParkingSquare.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Services-ur',
                'icon_name' => 'Services',
                'media_icon' => '/uploads/media_icons/1749015265_TdesignService.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Not included-ur',
                'icon_name' => 'Not included',
                'media_icon' => '/uploads/media_icons/1749015723_StreamlineInterfaceDeleteCircleButtonDeleteRemoveAddCircleButtons.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Garden view-ur',
                'icon_name' => 'Garden view',
                'media_icon' => '/uploads/media_icons/1749016317_LucideLabFlowerStem.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Hair dryer-ur',
                'icon_name' => 'Hair dryer',
                'media_icon' => '/uploads/media_icons/1749016497_MdiHairDryerOutline.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Cleaning products-ur',
                'icon_name' => 'Cleaning products',
                'media_icon' => '/uploads/media_icons/1749016678_HugeiconsCleaningBucket.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Conditioner-ur',
                'icon_name' => 'Conditioner',
                'media_icon' => '/uploads/media_icons/1749016946_HugeiconsShampoo.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'liquid-soap-ur',
                'icon_name' => 'liquid-soap',
                'media_icon' => '/uploads/media_icons/1749017242_PhHandSoapBold.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Hot water-ur',
                'icon_name' => 'Hot water',
                'media_icon' => '/uploads/media_icons/1749017473_StreamlineTravelWafinderSinkWashCleanToiletBathroomWater.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Essentials-ur',
                'icon_name' => 'Essentials',
                'media_icon' => '/uploads/media_icons/1749019653_LucideLabBottleToothbrushComb.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Hangers-ur',
                'icon_name' => 'Hangers',
                'media_icon' => '/uploads/media_icons/1749019737_SolarHangerBold.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Bed linen-ur',
                'icon_name' => 'Bed linen',
                'media_icon' => '/uploads/media_icons/1749019909_MaterialSymbolsLightKingBedOutlineSharp.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'icon_css_id' => 'Extra pillows and blankets-ur',
                'icon_name' => 'Extra pillows and blankets',
                'media_icon' => '/uploads/media_icons/1749020008_LucideLabPillow.png',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ["icon_css_id" => "Room-darkening blinds-ur", "icon_name" => "Room-darkening blinds", "media_icon" => "/uploads/media_icons/1749020136_MdiBlinds.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Iron-ur", "icon_name" => "Iron", "media_icon" => "/uploads/media_icons/1749020220_TablerIroningSteam.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Clothes storage-ur", "icon_name" => "Clothes storage", "media_icon" => "/uploads/media_icons/1749021201_LucideLabWardrobe.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Ethernet connection-ur", "icon_name" => "Ethernet connection", "media_icon" => "/uploads/media_icons/1749021378_IconParkOutlineEthernetOn.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Exercise equipment & yoga mat-ur", "icon_name" => "Exercise equipment & yoga mat", "media_icon" => "/uploads/media_icons/1749021730_HealthiconsExerciseOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Books and reading material-ur", "icon_name" => "Books and reading material", "media_icon" => "/uploads/media_icons/1749021912_MaterialSymbolsMenuBookOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Outdoor playground-ur", "icon_name" => "Outdoor playground", "media_icon" => "/uploads/media_icons/1749022960_FluentEmojiHighContrastPlaygroundSlide.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Air conditioning-ur", "icon_name" => "Air conditioning", "media_icon" => "/uploads/media_icons/1749023091_SolarSnowflakeBold.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Ceiling fan-ur", "icon_name" => "Ceiling fan", "media_icon" => "/uploads/media_icons/1749023206_CbiCeilingFanAlt.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Portable fans-ur", "icon_name" => "Portable fans", "media_icon" => "/uploads/media_icons/1749023386_CbiPedastalFan.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Portable heater-ur", "icon_name" => "Portable heater", "media_icon" => "/uploads/media_icons/1749023559_OuiTemperature.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Exterior security cameras on property-ur", "icon_name" => "Exterior security cameras on property", "media_icon" => "/uploads/media_icons/1749023669_GameIconsCctvCamera.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Fire extinguisher-ur", "icon_name" => "Fire extinguisher", "media_icon" => "/uploads/media_icons/1749023776_LucideFireExtinguisher.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "First aid kit-ur", "icon_name" => "First aid kit", "media_icon" => "/uploads/media_icons/1749023868_TablerFirstAidKit.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Wifi-ur", "icon_name" => "Wifi", "media_icon" => "/uploads/media_icons/1749023963_MaterialSymbolsWifiRounded.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Dedicated workspace-ur", "icon_name" => "Dedicated workspace", "media_icon" => "/uploads/media_icons/1749024624_HugeiconsStudyDesk.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Kitchen-ur", "icon_name" => "Kitchen", "media_icon" => "/uploads/media_icons/1749024714_HugeiconsKitchenUtensils.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Fridge-ur", "icon_name" => "Fridge", "media_icon" => "/uploads/media_icons/1749024801_MdiFridgeOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Cooking basics-ur", "icon_name" => "Cooking basics", "media_icon" => "/uploads/media_icons/1749024989_HugeiconsKitchenUtensils.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Dishes and cutlery-ur", "icon_name" => "Dishes and cutlery", "media_icon" => "/uploads/media_icons/1749028051_StreamlineFoodKitchenwareSpoonPlateForkPlateFoodDineCookUtensilsEatRestaurantDining.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Stainless steel cooker-ur", "icon_name" => "Stainless steel cooker", "media_icon" => "/uploads/media_icons/1749028262_MingcuteElectricCookerLine.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Oven-ur", "icon_name" => "Oven", "media_icon" => "/uploads/media_icons/1749028351_MaterialSymbolsOvenGenOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Kettle-ur", "icon_name" => "Kettle", "media_icon" => "/uploads/media_icons/1749028580_MaterialSymbolsKettleOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Coffee maker-ur", "icon_name" => "Coffee maker", "media_icon" => "/uploads/media_icons/1749028663_MaterialSymbolsCoffeeMakerOutline.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Baking sheet-ur", "icon_name" => "Baking sheet", "media_icon" => "/uploads/media_icons/1749029172_FluentFoodCake12Regular.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Blender-ur", "icon_name" => "Blender", "media_icon" => "/uploads/media_icons/1749029272_MaterialSymbolsLightBlender.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Dining table-ur", "icon_name" => "Dining table", "media_icon" => "/uploads/media_icons/1749029467_LucideLabChairsTablePlatter.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Private entrance-ur", "icon_name" => "Private entrance", "media_icon" => "/uploads/media_icons/1749029639_MaterialSymbolsDoorFrontOutlineRounded.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Private patio or balcony-ur", "icon_name" => "Private patio or balcony", "media_icon" => "/uploads/media_icons/1749029733_IconoirBalcony.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Outdoor furniture-ur", "icon_name" => "Outdoor furniture", "media_icon" => "/uploads/media_icons/1749029946_LucideLabChairsTableParasol.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Free parking on premises-ur", "icon_name" => "Free parking on premises", "media_icon" => "/uploads/media_icons/1749030238_IcOutlineDirectionsCar.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Self check-in-ur", "icon_name" => "Self check-in", "media_icon" => "/uploads/media_icons/1749030469_HumbleiconsKey.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Smart lock-ur", "icon_name" => "Smart lock", "media_icon" => "/uploads/media_icons/1749030582_StreamlineInterfacePadLockShieldCombinationComboLockLockedPadlockSecureSecurityShieldSquare.png", "created_at" => now(), "updated_at" => now()],

            ["icon_css_id" => "Housekeeping available every day-ur", "icon_name" => "Housekeeping available every day", "media_icon" => "/uploads/media_icons/1749030927_LucideLabBottleSpray.png", "created_at" => now(), "updated_at" => now()]


        ];


        foreach ($mediaData as $media) {
            Media::updateOrCreate(
                [
                    'icon_css_id' => $media['icon_css_id'],
                ],
                [
                    'icon_name' => $media['icon_name'],
                    'media_icon' => $media['media_icon'],
                    'updated_at' => now(),
                ]
            );
        }
    }
}
