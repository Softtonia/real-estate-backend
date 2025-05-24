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
        Schema::create('property_property_type', function (Blueprint $table) {
            $table->unsignedBigInteger('propertylist_id');
            $table->unsignedBigInteger('property_type_id');
            $table->foreign('propertylist_id')->references('id')->on('propertylists')->onDelete('cascade');
            $table->foreign('property_type_id')->references('id')->on('property_types')->onDelete('cascade');
            // Add any additional columns if needed
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_property_type');
    }
};
