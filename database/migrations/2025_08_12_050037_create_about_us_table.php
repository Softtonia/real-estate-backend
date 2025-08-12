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
        Schema::create('about_us', function (Blueprint $table) {
            $table->id();
            $table->string('page_title')->default('About Us');

            // Main sections - using longText for flexibility
            $table->longText('about_urbanrealities')->nullable();
            $table->longText('what_we_offer')->nullable();
            $table->longText('for_buyers_renters')->nullable();
            $table->longText('for_sellers_landlords')->nullable();
            $table->longText('our_mission_and_vision')->nullable();

            // SEO fields
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('about_us');
    }
};
