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
        Schema::create('city_visits', function (Blueprint $table) {
             $table->id();
            $table->unsignedBigInteger('city_id'); // City reference
            $table->unsignedBigInteger('user_id')->nullable(); // Optional:
            $table->integer('count')->default(1)->comment('Number of visits');
            $table->timestamps();

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_visits');
    }
};
