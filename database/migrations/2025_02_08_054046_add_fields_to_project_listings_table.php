<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
            // Remove foreign keys for created_by and updated_by, but keep them as columns
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Keep country, state, and city foreign keys
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])->default('Under Review');
            $table->enum('temporary_status', ['active', 'deactive'])->default('active');

            // Foreign keys only for location fields
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('CASCADE');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('CASCADE');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
            // Drop only the location foreign keys
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);

            // Drop columns including created_by and updated_by
            $table->dropColumn(['created_by', 'updated_by', 'country_id', 'state_id', 'city_id', 'live_status', 'temporary_status']);
        });
    }
};