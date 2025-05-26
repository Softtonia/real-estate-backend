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
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('display_amenities_order')->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->string('media_name')->nullable();
            $table->string('media_css')->nullable();
            $table->unsignedBigInteger('amenities_categories_id');
            $table->foreign('amenities_categories_id')
                  ->references('id')
                  ->on('amenities_categories')
                  ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenities');
    }
};
