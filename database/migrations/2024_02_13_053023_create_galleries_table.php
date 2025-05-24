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
        Schema::create('galleries', function (Blueprint $table) {
        $table->id();
            $table->unsignedBigInteger('propertylist_id');
            $table->string('image');
            $table->timestamps();

            // Define foreign key constraint
            $table->foreign('propertylist_id')->references('id')->on('properties_listing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('galleries');
    }
};
