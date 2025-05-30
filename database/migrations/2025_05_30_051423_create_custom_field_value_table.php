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
        Schema::create('custom_field_value', function (Blueprint $table) {
            $table->increments('custom_value_id');
            $table->string('properties_listing_id', 200)->nullable(); // Not adding FK unless it's numeric
            $table->unsignedBigInteger('project_listing_id')->nullable();
            $table->unsignedBigInteger('developer_listing_id')->nullable();
            $table->unsignedBigInteger('custom_field_id')->nullable();
            $table->unsignedBigInteger('custom_field_options_id')->nullable();
            $table->longText('field_meta_value')->nullable();

            $table->timestamp('created_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('updated_at')->nullable()->useCurrent();

            // Foreign Key Constraints
            $table->foreign('project_listing_id')->references('id')->on('project_listings')->onDelete('set null');
            $table->foreign('developer_listing_id')->references('id')->on('developer_listings')->onDelete('set null');
            $table->foreign('custom_field_id')->references('id')->on('custom_fields')->onDelete('set null');
            $table->foreign('custom_field_options_id')->references('id')->on('custom_field_options')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_value');
    }
};
