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
        Schema::create('properties_listing', function (Blueprint $table) {
            $table->id();
            $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review','Modify Review'])->default('Under Review');
            $table->enum('temporary_status', ['active', 'deactive'])->default('active');

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();

            $table->string('property_unique_id', 20)->nullable();
            $table->string('name', 200)->nullable();
            $table->longText('description')->nullable();
            $table->string('property_address', 255)->nullable();

            $table->string('featured_image', 200)->nullable();

            $table->integer('purpose_id')->nullable();
            $table->integer('property_id')->nullable();
            $table->integer('property_status_id')->nullable();
            $table->string('property_type_id', 255)->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('status_reason', 100)->nullable();
            $table->timestamps();

             // Foreign Key Constraints
             $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
             $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
             $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
             $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
             $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties_listing');
    }
};
