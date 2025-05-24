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
            // Drop 'status' column if it exists
            if (Schema::hasColumn('properties_listing', 'status')) {
                $table->dropColumn('status');
            }

            // Add 'live_status' column with new ENUM values
            $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])
                  ->default('Under Review')
                  ->after('id');

            // Add 'temporary_status' column with ENUM values
            $table->enum('temporary_status', ['active', 'deactive'])
                  ->default('active')
                  ->after('live_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties_listing', function (Blueprint $table) {
            // Drop new columns if they exist
            if (Schema::hasColumn('properties_listing', 'live_status')) {
                $table->dropColumn('live_status');
            }
            if (Schema::hasColumn('properties_listing', 'temporary_status')) {
                $table->dropColumn('temporary_status');
            }

            // Re-add the original 'status' column
            $table->enum('status', ['approved', 'reject', 'pending'])
                  ->default('pending')
                  ->after('id');
        });
    }
};
