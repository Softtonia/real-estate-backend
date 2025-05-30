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
        Schema::create('builders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('properties_id')->nullable();
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->string('builders_name')->nullable();
            $table->text('description')->nullable();
            $table->string('ongoing_project', 100)->nullable();
            $table->string('city')->nullable();
            $table->string('rera', 200)->nullable();
            $table->string('builders_unique_id', 200)->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->foreign('properties_id')->references('id')->on('properties')->onDelete('cascade');
            $table->foreign('property_type_id')->references('id')->on('property_types')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('builders');
    }
};
