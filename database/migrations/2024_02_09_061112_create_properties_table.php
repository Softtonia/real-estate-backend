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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('location');
            $table->text('amenities');
            $table->string('carpet_area')->nullable();
            $table->integer('additional_room')->nullable();
            $table->enum('facing', ['North', 'South', 'East', 'West'])->nullable();
            $table->integer('floor')->nullable();
            $table->text('available_room')->nullable();
            $table->string('property_address')->nullable();
            $table->string('lift')->nullable();
            $table->string('floor_plan_name')->nullable();
            $table->string('floor_plan_2d')->nullable();
            $table->string('property_video')->nullable();
            $table->string('video_url')->nullable();
            $table->string('video_thumbnail')->nullable();
            $table->string('featured_image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
