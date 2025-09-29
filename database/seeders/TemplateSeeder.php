<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ["show" => "Owner.name", "name" => "ownername", "type" => "property_list"],
            ["show" => "Contact.number", "name" => "contactnumber", "type" => "property_list"],
            ["show" => "Email", "name" => "email", "type" => "property_list"],
            ["show" => "Property.price", "name" => "propertyprice", "type" => "property_list"],
            ["show" => "Area.sq.ft", "name" => "areasqft", "type" => "property_list"],
            ["show" => "Bedrooms", "name" => "bedrooms", "type" => "property_list"],
            ["show" => "Bathrooms", "name" => "bathrooms", "type" => "property_list"],
            ["show" => "Balconies", "name" => "balconies", "type" => "property_list"],
            ["show" => "Furnishing.status", "name" => "furnishingstatus", "type" => "property_list"],
            ["show" => "Possession.date", "name" => "possessiondate", "type" => "property_list"],
            ["show" => "Property.images", "name" => "propertyimages", "type" => "property_list"],
            ["show" => "Property.video", "name" => "propertyvideo", "type" => "property_list"],
            ["show" => "Property.description", "name" => "propertydescription", "type" => "property_list"],

            [
                "show" => "Property.area.super Area",
                "name" => "propertyareasuper-area",
                "type" => "property_list"
            ],
            [

                "show" => "Property.area.carpet Area",
                "name" => "propertyareacarpet-area",
                "type" => "property_list"
            ],
            [

                "show" => "Property.price.booking Amount",
                "name" => "propertypricebooking-amount",
                "type" => "property_list"
            ],
            [

                "show" => "Property.price.total Price",
                "name" => "propertypricetotal-price",
                "type" => "property_list"
            ],
            [

                "show" => "Property.price.price Per Sqft",
                "name" => "propertypriceprice-per-sqft",
                "type" => "property_list"
            ],
            [

                "show" => "Property.price.payment Plan",
                "name" => "propertypricepayment-plan",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.status",
                "name" => "propertyfurnishingstatus",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.bedrooms",
                "name" => "propertyfurnishingbedrooms",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.bathrooms",
                "name" => "propertyfurnishingbathrooms",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.balconies",
                "name" => "propertyfurnishingbalconies",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.kitchen",
                "name" => "propertyfurnishingkitchen",
                "type" => "property_list"
            ],
            [

                "show" => "Property.furnishing.parking Spaces",
                "name" => "propertyfurnishingparking-spaces",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.security Fire Alarm",
                "name" => "propertyfeaturessecurity-fire-alarm",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.lift",
                "name" => "propertyfeatureslift",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.power Backup",
                "name" => "propertyfeaturespower-backup",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.water Supply",
                "name" => "propertyfeatureswater-supply",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.club House Gym Pool",
                "name" => "propertyfeaturesclub-house-gym-pool",
                "type" => "property_list"
            ],
            [

                "show" => "Property.features.green Area Park View",
                "name" => "propertyfeaturesgreen-area-park-view",
                "type" => "property_list"
            ],
            [

                "show" => "Project.area.super Area",
                "name" => "projectareasuper-area",
                "type" => "project_list"
            ],
            [

                "show" => "Project.area.built Up Area",
                "name" => "projectareabuilt-up-area",
                "type" => "project_list"
            ],
            [
                "show" => "Project.area.carpet Area",
                "name" => "projectareacarpet-area",
                "type" => "project_list"
            ],
            [
                "show" => "Project.area.total Land Area",
                "name" => "projectareatotal-land-area",
                "type" => "project_list"
            ],
            [
                "show" => "Project.area.floor No",
                "name" => "projectareafloor-no",
                "type" => "project_list"
            ],
            [
                "show" => "Project.area.total Floors",
                "name" => "projectareatotal-floors",
                "type" => "project_list"
            ],
            [
                "show" => "Project.area.ceiling Height",
                "name" => "projectareaceiling-height",
                "type" => "project_list"
            ],
            [

                "show" => "Project.area.shed Height",
                "name" => "projectareashed-height",
                "type" => "project_list"
            ],
            [

                "show" => "Project.area.floor Load Capacity",
                "name" => "projectareafloor-load-capacity",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price.booking Amount",
                "name" => "projectpricebooking-amount",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price.price Per Sqft",
                "name" => "projectpriceprice-per-sqft",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price.total Price",
                "name" => "projectpricetotal-price",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price.maintenance Charges",
                "name" => "projectpricemaintenance-charges",
                "type" => "project_list"
            ],
            [
                "show" => "Project.price.lease Availability",
                "name" => "projectpricelease-availability",
                "type" => "project_list"
            ],
            [
                "show" => "Project.price.expected Rent",
                "name" => "projectpriceexpected-rent",
                "type" => "project_list"
            ],
            [
                "show" => "Project.price.security Deposit",
                "name" => "projectpricesecurity-deposit",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price.price Per Acre",
                "name" => "projectpriceprice-per-acre",
                "type" => "project_list"
            ],
            [

                "show" => "Project.description.details",
                "name" => "projectdescriptiondetails",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.bedrooms",
                "name" => "projectfurnishingbedrooms",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.bathrooms",
                "name" => "projectfurnishingbathrooms",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.balconies",
                "name" => "projectfurnishingbalconies",
                "type" => "project_list"
            ],
            [
                "show" => "Project.furnishing.status",
                "name" => "projectfurnishingstatus",
                "type" => "project_list"
            ],
            [
                "show" => "Project.furnishing.parking",
                "name" => "projectfurnishingparking",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.conference Room",
                "name" => "projectfurnishingconference-room",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.pantry Cafeteria",
                "name" => "projectfurnishingpantry-cafeteria",
                "type" => "project_list"
            ],
            [

                "show" => "Project.furnishing.washrooms",
                "name" => "projectfurnishingwashrooms",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.security Fire Alarm",
                "name" => "projectfeaturessecurity-fire-alarm",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.lift",
                "name" => "projectfeatureslift",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.power Backup",
                "name" => "projectfeaturespower-backup",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.gymnasium",
                "name" => "projectfeaturesgymnasium",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.swimming Pool",
                "name" => "projectfeaturesswimming-pool",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.club House",
                "name" => "projectfeaturesclub-house",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.children Play Area",
                "name" => "projectfeatureschildren-play-area",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.garden Park",
                "name" => "projectfeaturesgarden-park",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.community Hall",
                "name" => "projectfeaturescommunity-hall",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.visitor Parking",
                "name" => "projectfeaturesvisitor-parking",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.water Supply",
                "name" => "projectfeatureswater-supply",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.air Conditioning",
                "name" => "projectfeaturesair-conditioning",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.server Room",
                "name" => "projectfeaturesserver-room",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.business Lounge",
                "name" => "projectfeaturesbusiness-lounge",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.electricity Connection",
                "name" => "projectfeatureselectricity-connection",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.road Access",
                "name" => "projectfeaturesroad-access",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.storage Facility",
                "name" => "projectfeaturesstorage-facility",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.boundary Wall",
                "name" => "projectfeaturesboundary-wall",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.office Block",
                "name" => "projectfeaturesoffice-block",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.canteen",
                "name" => "projectfeaturescanteen",
                "type" => "project_list"
            ],
            [
                "show" => "Project.features.security Cabin",
                "name" => "projectfeaturessecurity-cabin",
                "type" => "project_list"
            ],
            [

                "show" => "Project.features.green Zone",
                "name" => "projectfeaturesgreen-zone",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.survey Number",
                "name" => "projectagriculturesurvey-number",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.irrigated Area",
                "name" => "projectagricultureirrigated-area",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.non Irrigated Area",
                "name" => "projectagriculturenon-irrigated-area",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.soil Type",
                "name" => "projectagriculturesoil-type",
                "type" => "project_list"
            ],
            [
                "show" => "Project.agriculture.water Source",
                "name" => "projectagriculturewater-source",
                "type" => "project_list"
            ],
            [
                "show" => "Project.agriculture.borewells Count",
                "name" => "projectagricultureborewells-count",
                "type" => "project_list"
            ],
            [
                "show" => "Project.agriculture.fencing",
                "name" => "projectagriculturefencing",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.current Crops",
                "name" => "projectagriculturecurrent-crops",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.suitable Crops",
                "name" => "projectagriculturesuitable-crops",
                "type" => "project_list"
            ],
            [

                "show" => "Project.agriculture.livestock Facilities",
                "name" => "projectagriculturelivestock-facilities",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.industry Type",
                "name" => "projectindustrialindustry-type",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.compliance Certificates",
                "name" => "projectindustrialcompliance-certificates",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.docking Bays",
                "name" => "projectindustrialdocking-bays",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.power Load Capacity",
                "name" => "projectindustrialpower-load-capacity",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.gas Connection",
                "name" => "projectindustrialgas-connection",
                "type" => "project_list"
            ],
            [

                "show" => "Project.industrial.cranes",
                "name" => "projectindustrialcranes",
                "type" => "project_list"
            ],
            [

                "show" => "Project.location.city",
                "name" => "projectlocationcity",
                "type" => "project_list"
            ],
            [

                "show" => "Project.location.address",
                "name" => "projectlocationaddress",
                "type" => "project_list"
            ],
            [

                "show" => "Project.location.landmarks",
                "name" => "projectlocationlandmarks",
                "type" => "project_list"
            ],
            [

                "show" => "Project.location.proximity Airport",
                "name" => "projectlocationproximity-airport",
                "type" => "project_list"
            ],
            [

                "show" => "Project.location.proximity Seaport",
                "name" => "projectlocationproximity-seaport",
                "type" => "project_list"
            ],
            [

                "show" => "Project.media.images",
                "name" => "projectmediaimages",
                "type" => "project_list"
            ],
            [

                "show" => "Project.media.floor Plans",
                "name" => "projectmediafloor-plans",
                "type" => "project_list"
            ],
            [

                "show" => "Project.media.video Tour",
                "name" => "projectmediavideo-tour",
                "type" => "project_list"
            ],
            [

                "show" => "Project.media.survey Map",
                "name" => "projectmediasurvey-map",
                "type" => "project_list"
            ],
            [

                "show" => "Project.media.drone Footage",
                "name" => "projectmediadrone-footage",
                "type" => "project_list"
            ],
            [

                "show" => "Project.floor-plan",
                "name" => "projectfloor-plan",
                "type" => "project_list"
            ],
            [

                "show" => "Project.rera-number",
                "name" => "projectrera-number",
                "type" => "project_list"
            ],
            [

                "show" => "Project.logo",
                "name" => "projectlogo",
                "type" => "project_list"
            ],
            [

                "show" => "Project.price-range",
                "name" => "projectprice-range",
                "type" => "project_list"
            ],
            [

                "show" => "Project.possession-date",
                "name" => "projectpossession-date",
                "type" => "project_list"
            ],
            [

                "show" => "Project.brochure",
                "name" => "projectbrochure",
                "type" => "project_list"
            ],
            [

                "show" => "Project.certificates",
                "name" => "projectcertificates",
                "type" => "project_list"
            ],
            [

                "show" => "Project.why-us",
                "name" => "projectwhy-us",
                "type" => "project_list"
            ],
            [

                "show" => "Project.property-configuration",
                "name" => "projectproperty-configuration",
                "type" => "project_list"
            ],
            [

                "show" => "Developer.bedrooms",
                "name" => "developerbedrooms",
                "type" => "developer_list"
            ],
            [

                "show" => "Developer.area-sqft",
                "name" => "developerarea-sqft",
                "type" => "developer_list"
            ],
            [

                "show" => "Developer.price",
                "name" => "developerprice",
                "type" => "developer_list"
            ],
            [

                "show" => "Developer.experience",
                "name" => "developerexperience",
                "type" => "developer_list"
            ],
            [

                "show" => "Developer.rera-number",
                "name" => "developerrera-number",
                "type" => "developer_list"
            ],
            [

                "show" => "Pg-Gender",
                "name" => "pg-gender",
                "type" => "property_list"
            ],
            [

                "show" => "Pg-looking For",
                "name" => "pg-looking-for",
                "type" => "developer_list"
            ]
        ];

        foreach ($data as $item) {
            DB::table('custom_field_unique_codes')->insert([
                'name' => $item['show'], // display name
                'slug' => $item['name'], // machine name
                'post_type' => $item['type'],
                'status' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
