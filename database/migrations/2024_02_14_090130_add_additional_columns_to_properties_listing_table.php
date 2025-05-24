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
        Schema::table('properties_listing', function (Blueprint $table) {
            $table->decimal('booking_amount', 10, 2)->nullable();
            $table->decimal('price_per_sqft', 10, 2)->nullable();
            $table->decimal('basic_price', 10, 2)->nullable();
            $table->decimal('corpus', 10, 2)->nullable();
            $table->decimal('high_rise_charge', 10, 2)->nullable();
            $table->decimal('corner_charge', 10, 2)->nullable();
            $table->decimal('parking_space_charges', 10, 2)->nullable();
            $table->decimal('amenities_charges', 10, 2)->nullable();
            $table->decimal('rental_value', 10, 2)->nullable();
            $table->decimal('maintenance_charges', 10, 2)->nullable();
            $table->decimal('stamp_duties', 10, 2)->nullable();
            $table->decimal('registration_charges', 10, 2)->nullable();
            $table->decimal('gst', 10, 2)->nullable();
            $table->decimal('legal_expenses', 10, 2)->nullable();
            $table->decimal('documentation_charges', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties_listing', function (Blueprint $table) {
            //
        });
    }
};
