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
        Schema::create('top_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('section_name')->nullable();
            $table->enum('status', ['1', '0'])->default('1');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent();

            // Optional: Foreign key constraints (uncomment if needed)
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('project_listings')->onDelete('set null');
            $table->foreign('agent_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('top_features');
    }
};
