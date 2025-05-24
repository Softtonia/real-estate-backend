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
        Schema::create('amenity_propertylist', function (Blueprint $table) {
            $table->unsignedBigInteger('amenity_id');
            $table->unsignedBigInteger('propertylist_id');
            
            $table->foreign('amenity_id')->references('id')->on('amenities')->onDelete('cascade');
            $table->foreign('propertylist_id')->references('id')->on('propertylists')->onDelete('cascade');
            
            // Optionally, you can add additional columns to the pivot table if needed
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenity_propertylist');
    }
};
