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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
             $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->text('message')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('developer_id')->nullable();
            $table->json('user_ids')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('property_id')->references('id')->on('properties_listing')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('project_listings')->onDelete('cascade');
            $table->foreign('developer_id')->references('id')->on('developer_listings')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
