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
        Schema::table('user_details', function (Blueprint $table) {
            // Rename columns
            // Drop existing columns
            if (Schema::hasColumn('users_details', 'country')) {
                $table->dropColumn('country');
            }
            if (Schema::hasColumn('users_details', 'state')) {
                $table->dropColumn('state');
            }
            if (Schema::hasColumn('users_details', 'city')) {
                $table->dropColumn('city');
            }

            // Add new columns
            $table->unsignedBigInteger('country_id')->after('id')->nullable();
            $table->unsignedBigInteger('state_id')->after('country_id')->nullable();
            $table->unsignedBigInteger('city_id')->after('state_id')->nullable();

            // Add foreign key constraints
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
             // Remove foreign keys
             $table->dropForeign(['country_id']);
             $table->dropForeign(['state_id']);
             $table->dropForeign(['city_id']);
 
             // Drop new columns
             $table->dropColumn('country_id');
             $table->dropColumn('state_id');
             $table->dropColumn('city_id');
 
             // Restore old columns
             $table->string('country')->nullable();
             $table->string('state')->nullable();
             $table->string('city')->nullable();
        });
    }
};
