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
        Schema::table('developer_listings', function (Blueprint $table) {
            // Drop the location_id field
            $table->dropColumn('location_id');

            // Add country_id, state_id, city_id fields
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            // Optionally, you may want to add foreign key constraints
            // $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
            // $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
            // $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('developer_listings', function (Blueprint $table) {
            // Add back the location_id field
            $table->unsignedBigInteger('location_id')->nullable();

            // Remove the country_id, state_id, city_id fields
            $table->dropColumn(['country_id', 'state_id', 'city_id']);
        });
    }
};
