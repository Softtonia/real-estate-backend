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
        Schema::table('developer_listings', function (Blueprint $table) {
            // Ensure 'users' table exists before adding foreign key
            if (Schema::hasTable('users')) {
                // Add 'live_status' column with new ENUM values
                $table->enum('live_status', ['Approve', 'Disapprove', 'Reject', 'Under Review', 'Modify Review'])
                      ->default('Under Review')
                      ->after('id');

                // Add 'temporary_status' column with ENUM values
                $table->enum('temporary_status', ['active', 'deactive'])
                      ->default('active')
                      ->after('live_status');

                // Add new columns before foreign keys
                $table->integer('created_by')->nullable()->after('status_reason');
                $table->integer('updated_by')->nullable()->after('created_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('developer_listings', function (Blueprint $table) {
            // Drop foreign keys first
          
            // Drop columns
            if (Schema::hasColumn('developer_listings', 'created_by')) {
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('developer_listings', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
            if (Schema::hasColumn('developer_listings', 'live_status')) {
                $table->dropColumn('live_status');
            }
            if (Schema::hasColumn('developer_listings', 'temporary_status')) {
                $table->dropColumn('temporary_status');
            }
        });
    }
};
