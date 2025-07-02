<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
              $table->unsignedBigInteger('properties_listing_id')->nullable()->after('developer_listing_id');
              $table->unsignedBigInteger('project_listing_id')->nullable()->after('properties_listing_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_repeater_values', function (Blueprint $table) {
             $table->dropColumn(['properties_listing_id', 'project_listing_id']);
        });
    }
};
