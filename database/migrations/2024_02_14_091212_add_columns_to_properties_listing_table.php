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
            $table->string('katha_bifurcation')->nullable();
            $table->string('liaison')->nullable();
            $table->float('one_time_clubhouse_membership')->nullable();
            $table->float('society_formation_charges')->nullable();
            $table->float('electric_connection_charges')->nullable();
            $table->float('water_charges')->nullable();
            $table->float('infrastructure_development_charges')->nullable();
            $table->float('pipeline_gas_connection')->nullable();
            $table->float('brokerge_fee')->nullable();
            $table->float('interior_design_cost')->nullable();
            $table->float('additional_parking_charges')->nullable();
            $table->float('preferential_location_charge')->nullable();
            // Add more columns as needed
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
