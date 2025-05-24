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
        Schema::table('properties_listing', function (Blueprint $table) {
             // Add 'created_by' column to store the user ID who created the listing
             $table->unsignedBigInteger('created_by')->nullable()->after('temporary_status');

             // If you have a users table, you can add a foreign key
             $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties_listing', function (Blueprint $table) {
            // Drop the foreign key and column if it exists
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
